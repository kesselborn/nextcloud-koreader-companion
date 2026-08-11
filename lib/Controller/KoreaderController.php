<?php
namespace OCA\KoreaderCompanion\Controller;

use OCA\KoreaderCompanion\Service\DocumentHashGenerator;
use OCA\KoreaderCompanion\Service\BookService;
use OCA\KoreaderCompanion\Service\SyncPasswordService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\Config\IUserConfig;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class KoreaderController extends Controller {

    /**
     * How many books one sync request may hash while looking for a document.
     *
     * Auto-indexing used to walk the whole library and open every file, so a
     * single PUT carrying an unrecognised hash cost work proportional to the
     * library -- and an attacker could repeat it with random hashes. KOReader
     * retries, so a document beyond this bound still gets found across a few
     * syncs; `occ koreader:generate-hashes` maps the rest up front.
     */
    private const AUTO_INDEX_MAX_FILES = 200;

    /**
     * KOReader document hashes are MD5 hex. Anything else is not a hash we could
     * ever match, so it is rejected rather than stored.
     */
    private const DOCUMENT_HASH_LENGTH = 32;

    /**
     * Ceiling on stored progress rows per user. Unknown documents are recorded
     * on purpose (KOReader syncs books it has that the server has not indexed
     * yet), which without a cap is an unbounded attacker-controlled write.
     */
    private const MAX_PROGRESS_ROWS_PER_USER = 5000;

    private $db;
    private $userSession;
    private $userManager;
    private $config;
    private $hashGenerator;
    private $bookService;
    private $rootFolder;
    private $logger;

    public function __construct(
        $AppName, 
        IRequest $request, 
        IDBConnection $db, 
        IUserSession $userSession, 
        IUserManager $userManager, 
        IUserConfig $config,
        DocumentHashGenerator $hashGenerator,
        BookService $bookService,
        IRootFolder $rootFolder,
        LoggerInterface $logger,
        private SyncPasswordService $syncPasswords
    ) {
        parent::__construct($AppName, $request);
        $this->db = $db;
        $this->userSession = $userSession;
        $this->userManager = $userManager;
        $this->config = $config;
        $this->hashGenerator = $hashGenerator;
        $this->bookService = $bookService;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }

    
    #[NoCSRFRequired]
    #[PublicPage]
    #[NoAdminRequired]
    #[BruteForceProtection(action: 'koreader_sync')]
    public function authUser() {
        if (!$this->authenticateKoreader()) {
            $response = $this->createKoreaderResponse(['message' => 'Unauthorized'], 401);
            $response->throttle(['action' => 'koreader_sync']);
            return $response;
        }
        
        return $this->createKoreaderResponse(['message' => 'OK']);
    }

    #[NoCSRFRequired]
    #[PublicPage]
    #[NoAdminRequired]
    #[BruteForceProtection(action: 'koreader_sync')]
    public function getProgress($document) {
        if (!$this->authenticateKoreader()) {
            $response = $this->createKoreaderResponse(['message' => 'Unauthorized'], 401);
            $response->throttle(['action' => 'koreader_sync']);
            return $response;
        }

        $syncUser = $this->getCurrentSyncUser();
        if (!$syncUser) {
            return $this->createKoreaderResponse(['message' => 'Sync user not found'], 404);
        }
        
        // First try to get progress using the document hash directly
        $progress = $this->getDocumentProgress($syncUser['id'], $document);
        
        // If not found, try to find the document by hash and create mapping
        if (!$progress) {
            $bookInfo = $this->findBookByHash($document, $syncUser['id']);
            if (!$bookInfo) {
                $this->logUnknownDocument($document, $syncUser['id']);
                return $this->createKoreaderResponse(['message' => 'Document not found'], 404);
            }
            
            // Try again with the mapped document hash
            $progress = $this->getDocumentProgress($syncUser['id'], $document);
        }
        
        if (!$progress) {
            return $this->createKoreaderResponse(['message' => 'Document not found'], 404);
        }

        // Field set matches the reference kosync server
        // (koreader-sync-server, app/controllers/1/syncs_controller.lua):
        //   document, percentage, progress, device, device_id, timestamp
        //
        // document and timestamp used to be missing here, and both matter:
        //   - Omitting `document` broke pulls in Readest on iOS against custom
        //     servers (readest#5065, client-side fix in v0.11.20). Clients that
        //     key the response by document had nothing to match on.
        //   - Without `timestamp` a client cannot tell whether the server's
        //     progress is newer than its own, so conflict resolution degrades to
        //     "last writer wins" and a stale device can silently clobber.
        // timestamp is Unix epoch seconds, as in the reference server.
        $timestamp = 0;
        if (!empty($progress['updated_at'])) {
            $timestamp = (int)(new \DateTimeImmutable(
                $progress['updated_at'],
                new \DateTimeZone('UTC')
            ))->format('U');
        }

        return $this->createKoreaderResponse([
            'document' => $document,
            'progress' => $progress['progress'] ?? '',
            'percentage' => (float)($progress['percentage'] ?? 0.0),
            'device' => $progress['device'] ?? '',
            'device_id' => $progress['device_id'] ?? '',
            'timestamp' => $timestamp
        ]);
    }

    #[NoCSRFRequired]
    #[PublicPage]
    #[NoAdminRequired]
    #[BruteForceProtection(action: 'koreader_sync')]
    public function updateProgress() {
        if (!$this->authenticateKoreader()) {
            $response = $this->createKoreaderResponse(['message' => 'Unauthorized'], 401);
            $response->throttle(['action' => 'koreader_sync']);
            return $response;
        }

        $syncUser = $this->getCurrentSyncUser();
        if (!$syncUser) {
            return $this->createKoreaderResponse(['message' => 'Sync user not found'], 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $document = $input['document'] ?? '';
        $progress = $input['progress'] ?? null;
        $percentage = $input['percentage'] ?? null;
        $device = $input['device'] ?? '';
        $deviceId = $input['device_id'] ?? '';
        
        if (empty($document)) {
            return $this->createKoreaderResponse(['error' => 'Document hash required'], 400);
        }

        // Validated, not just non-empty. KOReader document hashes are MD5 hex; the
        // column used to accept any string of any shape, so the row key was fully
        // attacker-controlled.
        if (!is_string($document)
            || strlen($document) !== self::DOCUMENT_HASH_LENGTH
            || !ctype_xdigit($document)) {
            return $this->createKoreaderResponse(['error' => 'Malformed document hash'], 400);
        }

        // Try to find the document by hash to ensure it exists and create mapping if needed
        $bookInfo = $this->findBookByHash($document, $syncUser['id']);
        if (!$bookInfo) {
            // Document not found - try auto-indexing
            $indexed = $this->tryAutoIndex($document, $syncUser['id']);
            if (!$indexed) {
                $this->logUnknownDocument($document, $syncUser['id']);
                // Still save the progress even if we don't know the document
                // This maintains compatibility with existing KOReader behavior --
                // but only up to a ceiling, since unknown hashes are exactly what
                // an attacker can mint for free.
                // Only new rows are capped -- updating progress on a document
                // already being tracked must keep working at any library size.
                if ($this->getDocumentProgress($syncUser['id'], $document) === null
                    && $this->countProgressRows($syncUser['id']) >= self::MAX_PROGRESS_ROWS_PER_USER) {
                    return $this->createKoreaderResponse([
                        'message' => 'Too many tracked documents',
                    ], 507);
                }
            }
        }

        $this->saveDocumentProgress($syncUser['id'], $document, $progress, $percentage, $device, $deviceId);

        return $this->createKoreaderResponse(['message' => 'Progress updated']);
    }

    #[NoCSRFRequired]
    #[PublicPage]
    #[NoAdminRequired]
    public function healthcheck() {
        return $this->createKoreaderResponse(['state' => 'OK']);
    }

    private function authenticateKoreader() {
        $authUser = $this->request->getHeader('x-auth-user');
        $authKey = $this->request->getHeader('x-auth-key');

        if (!$authUser || !$authKey) {
            return false;
        }

        // KOReader always sends an MD5 hex digest. Anything else could never
        // match, so it is rejected before any lookup.
        if (strlen($authKey) !== self::DOCUMENT_HASH_LENGTH || !ctype_xdigit($authKey)) {
            return false;
        }

        // The user lookup no longer short-circuits. Returning early for an unknown
        // account made that response measurably faster than a wrong-key response,
        // which is enough to enumerate usernames -- so verification always runs,
        // against a dummy digest when there is nothing real to check.
        $user = $this->userManager->get($authUser);
        $verified = $this->syncPasswords->verify($authUser, $authKey);

        if (!$verified || $user === null) {
            return false;
        }

        // Set authenticated user in session
        $this->userSession->setUser($user);

        return true;
    }
    
    private function getCurrentSyncUser() {
        $authUser = $this->request->getHeader('x-auth-user');
        
        if (!$authUser) {
            return null;
        }
        
        // Check if user exists in Nextcloud
        $user = $this->userManager->get($authUser);
        if (!$user) {
            return null;
        }
        
        // Return basic user info for progress tracking
        return [
            'id' => $authUser,  // Use username as ID for progress table
            'username' => $authUser,
            'nextcloud_user_id' => $authUser
        ];
    }
    
    
    private function getDocumentProgress($userId, $document) {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from('koreader_sync_progress')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('document_hash', $qb->createNamedParameter($document)))
            ->executeQuery();
        
        $progress = $result->fetch();
        $result->closeCursor();
        
        return $progress ?: null;
    }
    
    private function countProgressRows($userId): int {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select($qb->func()->count('*', 'rows'))
            ->from('koreader_sync_progress')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    private function saveDocumentProgress($userId, $document, $progress, $percentage, $device, $deviceId) {
        $qb = $this->db->getQueryBuilder();
        
        // Check if progress exists
        $existing = $this->getDocumentProgress($userId, $document);
        
        if ($existing) {
            // Update existing record
            $qb->update('koreader_sync_progress')
                ->set('progress', $qb->createNamedParameter($progress))
                ->set('percentage', $qb->createNamedParameter($percentage))
                ->set('device', $qb->createNamedParameter($device))
                ->set('device_id', $qb->createNamedParameter($deviceId))
                ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('document_hash', $qb->createNamedParameter($document)))
                ->executeStatement();
        } else {
            // Insert new record
            $qb->insert('koreader_sync_progress')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'document_hash' => $qb->createNamedParameter($document),
                    'progress' => $qb->createNamedParameter($progress),
                    'percentage' => $qb->createNamedParameter($percentage),
                    'device' => $qb->createNamedParameter($device),
                    'device_id' => $qb->createNamedParameter($deviceId),
                    'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s'))
                ])
                ->executeStatement();
        }
    }

    /**
     * Find a book by its document hash using the hash mapping table
     *
     * @param string $documentHash MD5 hash from KOReader
     * @param string $userId Nextcloud username
     * @return array|null Book metadata if found, null if not found
     */
    private function findBookByHash(string $documentHash, string $userId): ?array {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('hm.metadata_id', 'hm.hash_type', 'em.file_id', 'em.title', 'em.author')
                ->from('koreader_hash_mapping', 'hm')
                ->innerJoin('hm', 'koreader_metadata', 'em', 'hm.metadata_id = em.id')
                ->where($qb->expr()->eq('hm.user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('hm.document_hash', $qb->createNamedParameter($documentHash)))
                ->executeQuery();

            $row = $result->fetch();
            $result->closeCursor();

            if ($row) {
                $this->logger->debug('Found book by hash in mapping table', [
                    'hash' => $documentHash,
                    'user' => $userId,
                    'title' => $row['title'],
                    'hash_type' => $row['hash_type']
                ]);
                return $row;
            }

            // Not found in mapping table - let's check if we can find it by scanning books
            $this->logger->debug('Book not found in hash mapping table', [
                'hash' => $documentHash,
                'user' => $userId
            ]);

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Error finding book by hash', [
                'hash' => $documentHash,
                'user' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Try to automatically index a document by scanning the user's books
     *
     * @param string $documentHash MD5 hash from KOReader
     * @param string $userId Nextcloud username
     * @return bool True if successfully indexed, false otherwise
     */
    private function tryAutoIndex(string $documentHash, string $userId): bool {
        try {
            $this->logger->debug('Attempting auto-index for unknown document', [
                'hash' => $documentHash,
                'user' => $userId
            ]);

            // Set user context for BookService
            $user = $this->userManager->get($userId);
            if (!$user) {
                $this->logger->debug('Auto-index failed: user not found', ['user' => $userId]);
                return false;
            }

            $this->userSession->setUser($user);

            // Get all books for this user
            $books = $this->bookService->getBooks();

            // Bounded. Every iteration opens a file, so an unbounded loop here let
            // one PUT with a random hash cost work proportional to the whole
            // library -- repeatable, and cheap for the caller.
            if (count($books) > self::AUTO_INDEX_MAX_FILES) {
                $this->logger->debug('Auto-index stopping early: library larger than the per-request bound', [
                    'app' => 'koreader_companion',
                    'books' => count($books),
                    'bound' => self::AUTO_INDEX_MAX_FILES,
                ]);
                $books = array_slice($books, 0, self::AUTO_INDEX_MAX_FILES);
            }

            // Check each book's hashes
            foreach ($books as $book) {
                try {
                    // Get the file node
                    $userFolder = $this->rootFolder->getUserFolder($userId);
                    $files = $userFolder->getById($book['id']);
                    
                    if (empty($files)) {
                        continue;
                    }
                    
                    $file = $files[0];
                    
                    // Generate both types of hashes
                    $binaryHash = $this->hashGenerator->generateBinaryHashFromNode($file);
                    $filenameHash = $this->hashGenerator->generateFilenameHashFromNode($file);
                    
                    // Check if either hash matches
                    if ($binaryHash === $documentHash || $filenameHash === $documentHash) {
                        $hashType = ($binaryHash === $documentHash) ? 'binary' : 'filename';
                        
                        $this->logger->debug('Auto-indexing document found match', [
                            'hash' => $documentHash,
                            'user' => $userId,
                            'file' => $file->getName(),
                            'hash_type' => $hashType
                        ]);

                        // Check if metadata record exists
                        $metadataId = $this->getOrCreateMetadataRecord($book, $userId);
                        if (!$metadataId) {
                            continue;
                        }

                        // Create hash mapping
                        $this->createHashMapping($userId, $documentHash, $hashType, $metadataId);
                        
                        return true;
                    }
                    
                } catch (\Exception $e) {
                    $this->logger->debug('Error processing book during auto-index', [
                        'book_id' => $book['id'],
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }

            $this->logger->debug('Auto-index completed: no matching document found', [
                'hash' => $documentHash,
                'user' => $userId,
                'books_checked' => count($books)
            ]);

            return false;

        } catch (\Exception $e) {
            $this->logger->error('Auto-index failed with exception', [
                'hash' => $documentHash,
                'user' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get or create metadata record for a book
     *
     * @param array $book Book information from BookService
     * @param string $userId Nextcloud username
     * @return int|null Metadata record ID, or null on error
     */
    private function getOrCreateMetadataRecord(array $book, string $userId): ?int {
        try {
            // First try to find existing metadata record
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($book['id'])))
                ->executeQuery();

            $row = $result->fetch();
            $result->closeCursor();

            if ($row) {
                return (int)$row['id'];
            }

            // Create new metadata record
            $insertQb = $this->db->getQueryBuilder();
            $insertQb->insert('koreader_metadata')
                ->values([
                    'user_id' => $insertQb->createNamedParameter($userId),
                    'file_id' => $insertQb->createNamedParameter($book['id']),
                    'title' => $insertQb->createNamedParameter($book['title'] ?? ''),
                    'author' => $insertQb->createNamedParameter($book['author'] ?? ''),
                    'description' => $insertQb->createNamedParameter($book['description'] ?? ''),
                    'language' => $insertQb->createNamedParameter($book['language'] ?? ''),
                    'publisher' => $insertQb->createNamedParameter($book['publisher'] ?? ''),
                    'publication_date' => $insertQb->createNamedParameter($this->parsePublicationDate($book['publication_date'] ?? '') ?? null),
                    'subject' => $insertQb->createNamedParameter($book['subject'] ?? ''),
                    'series' => $insertQb->createNamedParameter($book['series'] ?? ''),
                    'issue' => $insertQb->createNamedParameter($book['issue'] ?? ''),
                    'volume' => $insertQb->createNamedParameter($book['volume'] ?? ''),
                    'tags' => $insertQb->createNamedParameter($book['tags'] ?? ''),
                    'created_at' => $insertQb->createNamedParameter(date('Y-m-d H:i:s')),
                    'updated_at' => $insertQb->createNamedParameter(date('Y-m-d H:i:s'))
                ])
                ->executeStatement();

            return $this->db->lastInsertId();

        } catch (\Exception $e) {
            $this->logger->error('Error getting or creating metadata record', [
                'book_id' => $book['id'],
                'user' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create hash mapping entry
     *
     * @param string $userId Nextcloud username
     * @param string $documentHash Document hash
     * @param string $hashType Type of hash (binary or filename)
     * @param int $metadataId Metadata record ID
     * @return bool True on success, false on failure
     */
    private function createHashMapping(string $userId, string $documentHash, string $hashType, int $metadataId): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('koreader_hash_mapping')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'document_hash' => $qb->createNamedParameter($documentHash),
                    'hash_type' => $qb->createNamedParameter($hashType),
                    'metadata_id' => $qb->createNamedParameter($metadataId),
                    'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s'))
                ])
                ->executeStatement();

            $this->logger->debug('Created hash mapping entry', [
                'user' => $userId,
                'hash' => $documentHash,
                'hash_type' => $hashType,
                'metadata_id' => $metadataId
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Error creating hash mapping', [
                'user' => $userId,
                'hash' => $documentHash,
                'hash_type' => $hashType,
                'metadata_id' => $metadataId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Log unknown document hash
     *
     * @param string $documentHash MD5 hash from KOReader
     * @param string $userId Nextcloud username
     */
    private function logUnknownDocument(string $documentHash, string $userId): void {
        $this->logger->warning('Unknown document hash in KOReader sync', [
            'hash' => $documentHash,
            'user' => $userId,
            'message' => 'Document not found in hash mapping table and auto-indexing failed'
        ]);
        
        // Could also implement additional logging to a separate table for analytics
        // or administrative purposes if needed
    }

    /**
     * Parse various date formats into YYYY-MM-DD format for publication_date field
     */
    private function parsePublicationDate(?string $dateValue): ?string {
        if (empty($dateValue)) {
            return null;
        }

        $dateValue = trim($dateValue);

        // Handle 4-digit year format (most common case)
        if (preg_match('/^\d{4}$/', $dateValue)) {
            return $dateValue . '-01-01'; // Default to January 1st
        }

        // Handle YYYY-MM format
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $dateValue, $matches)) {
            $year = $matches[1];
            $month = sprintf('%02d', intval($matches[2]));
            return "$year-$month-01"; // Default to 1st of month
        }

        // Handle YYYY-MM-DD format (already correct)
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $dateValue, $matches)) {
            $year = $matches[1];
            $month = sprintf('%02d', intval($matches[2]));
            $day = sprintf('%02d', intval($matches[3]));
            
            // Validate the date
            if (checkdate(intval($month), intval($day), intval($year))) {
                return "$year-$month-$day";
            }
        }

        // Extract year from any string containing a 4-digit year
        if (preg_match('/(\d{4})/', $dateValue, $matches)) {
            $year = $matches[1];
            // Validate year range
            if (intval($year) >= 1000 && intval($year) <= 2099) {
                return "$year-01-01"; // Default to January 1st
            }
        }

        // Could not parse the date
        return null;
    }

    /**
     * Create KOReader-compliant JSON response
     * 
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     * @return JSONResponse
     */
    private function createKoreaderResponse(array $data, int $statusCode = 200): JSONResponse {
        $response = new JSONResponse($data, $statusCode);
        $response->addHeader('Content-Type', 'application/json');
        return $response;
    }

    /**
     * Validate client accepts KOReader content type
     * 
     * @return bool True if client accepts KOReader content type
     */
    private function validateAcceptHeader(): bool {
        $acceptHeader = $this->request->getHeader('Accept');
        
        // Accept if no Accept header is specified (backward compatibility)
        if (empty($acceptHeader)) {
            return true;
        }
        
        // Check for exact match or wildcard
        return strpos($acceptHeader, 'application/vnd.koreader.v1+json') !== false ||
               strpos($acceptHeader, 'application/*') !== false ||
               strpos($acceptHeader, '*/*') !== false;
    }
}
