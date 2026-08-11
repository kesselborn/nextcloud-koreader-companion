<?php
namespace OCA\KoreaderCompanion\Controller;

use OCA\KoreaderCompanion\Service\BookService;
use OCA\KoreaderCompanion\Service\FilenameService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller {

    private $config;
    private $userSession;
    private $db;
    private $bookService;
    private $rootFolder;
    private $filenameService;
    private $logger;

    public function __construct(IRequest $request, IUserConfig $config, IUserSession $userSession, IDBConnection $db, BookService $bookService, IRootFolder $rootFolder, FilenameService $filenameService, LoggerInterface $logger, $appName) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userSession = $userSession;
        $this->db = $db;
        $this->bookService = $bookService;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;

        if (!$filenameService) {
            throw new \Exception('FilenameService not available - required for filename operations');
        }
        $this->filenameService = $filenameService;
    }

    /**
     * Helper method to get authenticated user or return error response
     */
    private function getAuthenticatedUser() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not logged in'], 401);
        }
        return $user;
    }

    #[NoAdminRequired]
    public function setFolder($folder) {
        $user = $this->getAuthenticatedUser();
        if ($user instanceof JSONResponse) {
            return $user; // Return error response
        }

        $userId = $user->getUID();

        // Validated before it is persisted. This used to write whatever string
        // arrived, unchecked -- and an empty value makes $userFolder->get('')
        // resolve to the user's *root*, which turned every library listing into a
        // recursive scan and parse of their entire Nextcloud.
        $folder = trim((string)$folder, " \t\n\r\0\x0B/");
        if ($folder === '') {
            return new JSONResponse(['error' => 'Please choose a folder'], 400);
        }

        $userFolder = $this->rootFolder->getUserFolder($userId);
        try {
            $target = $userFolder->get($folder);
        } catch (\OCP\Files\NotFoundException $e) {
            return new JSONResponse(['error' => 'That folder does not exist'], 404);
        }

        if ($target->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
            return new JSONResponse(['error' => 'That is a file, not a folder'], 400);
        }

        // Belt and braces against traversal: whatever the string looked like, the
        // resolved node has to sit inside this user's own folder.
        if (!str_starts_with($target->getPath(), rtrim($userFolder->getPath(), '/') . '/')) {
            return new JSONResponse(['error' => 'That folder is outside your files'], 400);
        }

        $currentFolder = $this->config->getValueString($userId, $this->appName, 'folder', 'eBooks');

        // Check if folder is actually changing
        $isFolderChanging = ($currentFolder !== $folder);

        // Set the new folder
        $this->config->setValueString($userId, $this->appName, 'folder', $folder);

        // Automatically clear library metadata when folder changes
        if ($isFolderChanging) {
            $cleared = $this->clearLibraryMetadata($userId);

            // The reconciliation walk is throttled, so without this the new
            // folder would look empty until the interval elapsed.
            $this->config->setValueString($userId, $this->appName, 'last_reconcile', '0');
            return new JSONResponse([
                'folder_changed' => true,
                'cleared' => $cleared,
                'message' => $cleared > 0 ? "Folder updated and library cleared. {$cleared} books will need to be re-indexed." : 'Folder updated.'
            ]);
        }

        return new JSONResponse([
            'folder_changed' => false
        ]);
    }

    #[NoAdminRequired]
    public function setAutoRename($auto_rename) {
        $user = $this->getAuthenticatedUser();
        if ($user instanceof JSONResponse) {
            return $user; // Return error response
        }

        // Ensure we have a valid string value ('yes' or 'no')
        $value = ($auto_rename === 'yes') ? 'yes' : 'no';

        $this->config->setValueString($user->getUID(), $this->appName, 'auto_rename', $value);
        return new JSONResponse([]);
    }

    #[NoAdminRequired]
    // Walks and renames the whole library synchronously, so one press is minutes
    // of a PHP worker. Moving it to IJobList is the real fix (tracked as 8.2c);
    // until then a rate limit stops it being an amplifier.
    #[UserRateLimit(limit: 2, period: 300)]
    public function batchRename($auto_rename) {
        $user = $this->getAuthenticatedUser();
        if ($user instanceof JSONResponse) {
            return $user; // Return error response
        }

        $userId = $user->getUID();

        try {
            // First, enable auto-rename setting
            $this->config->setValueString($userId, $this->appName, 'auto_rename', $auto_rename);

            // Get user's eBooks folder
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $folderName = $this->config->getValueString($userId, $this->appName, 'folder', 'eBooks');

            try {
                $booksFolder = $userFolder->get($folderName);
            } catch (\OCP\Files\NotFoundException $e) {
                return new JSONResponse(['error' => 'eBooks folder not found'], 404);
            }

            $totalBooks = $this->bookService->getTotalBookCount();

            if ($totalBooks === 0) {
                return new JSONResponse([
                    'renamed_count' => 0,
                    'total_books' => 0
                ]);
            }

            // Process books immediately with chunked approach
            return $this->processBatchRenameImmediate($userId, $userFolder, $totalBooks);

        } catch (\Exception $e) {
            // The exception text used to go straight to the client, exposing
            // absolute paths and driver errors.
            $this->logger->error('Batch rename failed', [
                'app' => $this->appName,
                'exception' => $e,
            ]);
            return new JSONResponse(['error' => 'Batch rename failed'], 500);
        }
    }

    /**
     * Process batch rename immediately using optimized chunked processing
     */
    private function processBatchRenameImmediate(string $userId, $userFolder, int $totalBooks): JSONResponse {
        $renamedCount = 0;
        $processedCount = 0;
        $chunkSize = 100;

        // Initialize progress tracking
        $this->updateBatchRenameProgress($userId, 0, 0, $totalBooks, 'Starting batch rename...');

        // OPTIMIZATION: Sync filesystem metadata ONCE at start of batch operation
        // This eliminates 50+ redundant filesystem scans
        // Forced: a rename pass has to see the current filesystem, not whatever
        // the throttled reconciliation last recorded.
        $this->bookService->ensureMetadataUpToDate($userId, true);

        $this->updateBatchRenameProgress($userId, 5, 0, $totalBooks, 'Scanning library...');

        // Process books in chunks to handle medium libraries efficiently
        $totalPages = ceil($totalBooks / $chunkSize);

        for ($page = 1; $page <= $totalPages; $page++) {
            // Get chunk of books with metadata from database (skip metadata update)
            $books = $this->bookService->getBooks($page, $chunkSize, 'title', true);

            if (empty($books)) {
                continue; // Skip if database approach fails for this chunk
            }

            // Process each book in current chunk
            foreach ($books as $book) {
                try {
                    $fileId = $book['id'];
                    $files = $userFolder->getById($fileId);

                    if (empty($files)) {
                        continue; // File not found, skip
                    }

                    $file = $files[0];
                    $currentName = $file->getName();
                    $processedCount++;

                    // Generate standardized filename using the metadata from database
                    $newName = $this->filenameService->generateStandardFilename($book, $currentName);

                    if ($newName !== $currentName) {
                        // Check for conflicts and resolve
                        $parentFolder = $file->getParent();
                        $finalName = $this->filenameService->resolveFilenameConflict($parentFolder, $newName);

                        // Perform the rename
                        $file->move($parentFolder->getPath() . '/' . $finalName);
                        $renamedCount++;
                    }
                } catch (\Exception $e) {
                    // Log error but continue with other files
                    continue;
                }
            }

            // Update progress after each chunk
            $percent = 5 + (($page / $totalPages) * 90); // 5% for initial scan + 90% for processing
            $status = "Processing $processedCount/$totalBooks books... ($renamedCount renamed)";
            $this->updateBatchRenameProgress($userId, $percent, $renamedCount, $totalBooks, $status);

            // Reduced delay between chunks since we eliminated the filesystem scanning overhead
            if ($page < $totalPages) {
                usleep(500000); // 0.5s delay between chunks
            }
        }

        // Mark operation as completed
        $this->updateBatchRenameProgress($userId, 100, $renamedCount, $totalBooks, 'Completed');

        return new JSONResponse([
            'renamed_count' => $renamedCount,
            'total_books' => $totalBooks,
            'processed_count' => $processedCount,
            'processed_immediately' => true
        ]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getSettings() {
        $user = $this->getAuthenticatedUser();
        if ($user instanceof JSONResponse) {
            return $user; // Return error response
        }

        $userId = $user->getUID();
        return new JSONResponse([
            'folder' => $this->config->getValueString($userId, $this->appName, 'folder', 'eBooks'),
            'auto_rename' => $this->config->getValueString($userId, $this->appName, 'auto_rename', 'no')
        ]);
    }

    /**
     * Update batch rename progress for user
     */
    private function updateBatchRenameProgress(string $userId, float $percent, int $renamed, int $total, string $status) {
        $progressData = [
            'percent' => round($percent, 1),
            'renamed_count' => $renamed,
            'total_books' => $total,
            'status' => $status,
            'timestamp' => time()
        ];

        $this->config->setValueString($userId, $this->appName, 'batch_rename_progress', json_encode($progressData));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getBatchRenameProgress() {
        $user = $this->getAuthenticatedUser();
        if ($user instanceof JSONResponse) {
            return $user;
        }

        $userId = $user->getUID();
        $progressJson = $this->config->getValueString($userId, $this->appName, 'batch_rename_progress', '{}');
        $progress = json_decode($progressJson, true) ?: [];

        // Add default values if not set
        $progress = array_merge([
            'percent' => 0,
            'renamed_count' => 0,
            'total_books' => 0,
            'status' => 'Ready',
            'timestamp' => time()
        ], $progress);

        return new JSONResponse($progress);
    }

    /**
     * Clear library metadata while preserving sync progress
     *
     * @param string $userId User ID
     * @return int Number of metadata records cleared
     */
    private function clearLibraryMetadata($userId): int {
        try {
            $this->db->beginTransaction();

            // Count metadata records to be cleared
            $countQb = $this->db->getQueryBuilder();
            $result = $countQb->select($countQb->func()->count('*'))
                ->from('koreader_metadata')
                ->where($countQb->expr()->eq('user_id', $countQb->createNamedParameter($userId)))
                ->executeQuery();
            $count = (int) $result->fetchOne();

            if ($count > 0) {
                // Delete hash mappings first (they reference metadata)
                $hashQb = $this->db->getQueryBuilder();
                $hashQb->delete('koreader_hash_mapping')
                    ->where($hashQb->expr()->eq('user_id', $hashQb->createNamedParameter($userId)))
                    ->executeStatement();

                // Delete metadata records
                $metaQb = $this->db->getQueryBuilder();
                $metaQb->delete('koreader_metadata')
                    ->where($metaQb->expr()->eq('user_id', $metaQb->createNamedParameter($userId)))
                    ->executeStatement();
            }

            // Note: We deliberately do NOT clear koreader_sync_progress to preserve reading progress

            $this->db->commit();
            return $count;
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to clear library metadata', [
                'user' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

}
