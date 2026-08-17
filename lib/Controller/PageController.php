<?php
namespace OCA\KoreaderCompanion\Controller;

use OCA\KoreaderCompanion\Service\AnnotationService;
use OCA\KoreaderCompanion\Service\BookService;
use OCA\KoreaderCompanion\Service\DocumentHashGenerator;
use OCA\KoreaderCompanion\Service\FilenameService;
use OCA\KoreaderCompanion\Service\ReadingProgressService;
use OCA\KoreaderCompanion\Service\SyncPasswordService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\Config\IUserConfig;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCP\IDBConnection;
use OCP\Util;
use Psr\Log\LoggerInterface;

class PageController extends Controller {

    /**
     * Upload ceiling, independent of PHP's own upload_max_filesize.
     *
     * 512 MB matches the cap the preview providers already enforce
     * (CoverProvider::MAX_FILE_SIZE), so the two do not disagree about what is
     * too big to look at.
     */
    private const MAX_UPLOAD_BYTES = 512 * 1024 * 1024;

    private $bookService;
    private $config;
    private $userSession;
    private $urlGenerator;
    private $db;
    private $rootFolder;
    private $hashGenerator;
    private $filenameService;
    private LoggerInterface $logger;
    private IInitialState $initialState;

    public function __construct(
        IRequest $request,
        $appName,
        BookService $bookService,
        FilenameService $filenameService,
        IUserConfig $config,
        IUserSession $userSession,
        IURLGenerator $urlGenerator,
        IDBConnection $db,
        IRootFolder $rootFolder,
        DocumentHashGenerator $hashGenerator,
        IInitialState $initialState,
        LoggerInterface $logger,
        private SyncPasswordService $syncPasswords,
        private ReadingProgressService $readingProgress,
        private AnnotationService $annotations
    ) {
        parent::__construct($appName, $request);
        $this->bookService = $bookService;

        if (!$filenameService) {
            throw new \Exception('FilenameService not available - required for file operations');
        }
        $this->filenameService = $filenameService;

        $this->config = $config;
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
        $this->rootFolder = $rootFolder;
        $this->hashGenerator = $hashGenerator;
        $this->initialState = $initialState;
        $this->logger = $logger;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index() {
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = 50; // Fixed page size of 50 books
        $query = $this->request->getParam('q', '');
        
        $user = $this->userSession->getUser();
        
        $acceptHeader = $this->request->getHeader('Accept');
        $isAjax = (strpos($acceptHeader, 'application/json') !== false) || 
                  ($this->request->getHeader('X-Requested-With') === 'XMLHttpRequest');
        
        if ($isAjax) {
            // The sort parameter was read nowhere: this passed a hardcoded 'title'
            // and the search path hardcoded its own ORDER BY, so the sort control
            // in the UI had no effect at all. BookService allow-lists the value.
            //
            // Defaults to 'updated' to match the UI's own default. OPDS keeps its
            // title default -- catalogue clients expect alphabetical.
            $sort = (string)$this->request->getParam('sort', 'updated');

            // For AJAX requests, skip metadata updates (performance optimization)
            // Metadata is updated on initial page load and file uploads
            if (empty($query)) {
                $books = $this->bookService->getBooks($page, $perPage, $sort, true);
            } else {
                $books = $this->bookService->searchBooks($query, $page, $perPage, true, $sort);
            }
            return new JSONResponse($books);
        }

        // Trigger a metadata refresh, but do not embed the books in the HTML.
        // The old template rendered 50 books server-side and then immediately
        // re-fetched them over AJAX with different markup; the Vue app fetches
        // once through one code path.
        $this->bookService->ensureMetadataUpToDate($user ? $user->getUID() : '');

        // Built from the routes themselves, not by gluing paths onto the web root.
        //
        // Concatenation assumes the instance serves pretty URLs. Where it does not
        // -- no .htaccess rewrite, which is common behind a reverse proxy -- every
        // Nextcloud URL is really /index.php/apps/..., and the app still works
        // because Nextcloud generates its own links correctly. Only the addresses
        // shown here for pasting into an OPDS reader or a sync client were wrong,
        // so they 404'd on exactly the instances that need index.php.
        //
        // linkToRouteAbsolute() knows which form this instance uses. The sync base
        // is not a route itself, so it is derived from the one endpoint that is
        // guaranteed to exist and never moves.
        $opdsUrl = $this->urlGenerator->linkToRouteAbsolute('koreader_companion.opds.index');
        $healthcheckUrl = $this->urlGenerator->linkToRouteAbsolute('koreader_companion.koreader.healthcheck');
        $syncUrl = preg_replace('#/healthcheck$#', '', $healthcheckUrl);

        $hasKoreaderPassword = false;
        if ($user) {
            $hasKoreaderPassword = !empty($this->config->getValueString(
                $user->getUID(),
                'koreader_companion',
                'koreader_sync_password',
                ''
            ));
        }

        $this->initialState->provideInitialState('connection', [
            'opds_url' => $opdsUrl,
            'koreader_sync_url' => $syncUrl,
            'username' => $user ? $user->getUID() : '',
            'has_koreader_password' => $hasKoreaderPassword,
        ]);

        Util::addScript($this->appName, 'koreader_companion-main');
        Util::addStyle($this->appName, 'koreader_companion-main');

        $response = new TemplateResponse($this->appName, 'page');

        // The EPUB reader unpacks the book in the browser and hands the chapters,
        // stylesheets, fonts and images to an iframe as blob: URLs. Scripts stay
        // disallowed -- book content has no business running any.
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedFrameDomain('blob:');
        $csp->addAllowedStyleDomain('blob:');
        $csp->addAllowedFontDomain('blob:');
        $csp->addAllowedImageDomain('blob:');
        $csp->addAllowedMediaDomain('blob:');
        $response->setContentSecurityPolicy($csp);

        return $response;
    }

    #[NoAdminRequired]
    public function setKoreaderPassword() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        
        $password = (string)$this->request->getParam('password', '');

        // Length is checked server-side now. It used to be an empty() check here
        // and a four-character rule in the browser, so a one-character sync
        // password was accepted by anyone calling the endpoint directly.
        $error = $this->syncPasswords->setPassword($user->getUID(), $password);
        if ($error !== null) {
            return new DataResponse(['error' => $error], 400);
        }

        return new DataResponse([]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getKoreaderPassword() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        
        return new DataResponse([
            'password' => '', // Never return actual password for security
            'has_password' => $this->syncPasswords->hasPassword($user->getUID())
        ]);
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function uploadBook() {
        try {
            // Get the uploaded file
            $uploadedFiles = $this->request->getUploadedFile('file');
            if (!$uploadedFiles || !isset($uploadedFiles['tmp_name'])) {
                return new JSONResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
            }

            $rejection = $this->rejectUnacceptableUpload($uploadedFiles);
            if ($rejection !== null) {
                return $rejection;
            }

            // Get the user's configured eBooks folder
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
            }
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());

            // Use the configured folder name
            $folderName = $this->config->getValueString($user->getUID(), 'koreader_companion', 'folder', 'eBooks');

            try {
                $booksFolder = $userFolder->get($folderName);
            } catch (\OCP\Files\NotFoundException $e) {
                // Create folder if it doesn't exist
                $booksFolder = $userFolder->newFolder($folderName);
            }

            // Upload the file first. The name is sanitised before it ever reaches
            // newFile(): core does validate paths, but relying on that alone left
            // the app's own naming rules unenforced whenever auto-rename was off.
            $originalFilename = $this->filenameService->sanitizeUploadFilename($uploadedFiles['name']);
            $tempFilename = 'temp_' . time() . '_' . $originalFilename;
            $tempFile = $booksFolder->newFile($tempFilename);
            $tempFile->putContent(file_get_contents($uploadedFiles['tmp_name']));

            // Extract metadata from the uploaded file
            $extractedMetadata = $this->bookService->extractMetadataForUpload($tempFile);

            // Get user-provided metadata from request
            $publicationYear = $this->request->getParam('publication_date', '');

            // Validate publication year (must be 4-digit year or empty)
            if (!empty($publicationYear) && (!is_numeric($publicationYear) || strlen($publicationYear) !== 4 || intval($publicationYear) < 1000 || intval($publicationYear) > 2099)) {
                $tempFile->delete(); // Clean up temp file
                return new JSONResponse(['error' => 'Publication year must be a 4-digit year (1000-2099)'], Http::STATUS_BAD_REQUEST);
            }

            // Convert year to publication_date format (YYYY-MM-DD)
            $publicationDate = null;
            if (!empty($publicationYear)) {
                $publicationDate = $publicationYear . '-01-01'; // Default to January 1st
            }

            // Merge extracted metadata with user-provided metadata (user data takes precedence)
            $finalMetadata = array_merge($extractedMetadata, array_filter([
                'title' => $this->request->getParam('title', ''),
                'author' => $this->request->getParam('author', ''),
                'language' => $this->request->getParam('language', ''),
                'publisher' => $this->request->getParam('publisher', ''),
                'publication_date' => $publicationDate,
                'description' => $this->request->getParam('description', ''),
                'tags' => $this->request->getParam('tags', ''),
                'series' => $this->request->getParam('series', ''),
                'issue' => $this->request->getParam('issue', ''),
                'volume' => $this->request->getParam('volume', '')
            ], function($value) { return $value !== ''; }));

            // Check if auto-rename is enabled for this user
            $autoRename = $this->config->getValueString($user->getUID(), 'koreader_companion', 'auto_rename', 'no');

            if ($autoRename === 'yes') {
                // Generate standardized filename based on final metadata
                $finalFilename = $this->filenameService->generateStandardFilename($finalMetadata, $originalFilename);
            } else {
                // Keep original filename
                $finalFilename = $originalFilename;
            }

            // Check for conflicts and resolve with auto-renaming
            $finalFilename = $this->filenameService->resolveFilenameConflict($booksFolder, $finalFilename);

            // Move temp file to final location with final filename
            $finalPath = $booksFolder->getPath() . '/' . $finalFilename;
            $tempFile->move($finalPath);

            // Get the final file reference
            $finalFile = $booksFolder->get($finalFilename);

            // Store final metadata in database
            $this->storeBookMetadata($finalFile, $finalMetadata);

            return new JSONResponse([
                'filename' => $finalFilename,
                'path' => $finalPath,
                'extracted_metadata' => $extractedMetadata
            ]);

        } catch (\Exception $e) {
            return $this->internalError('Upload failed', $e);
        }
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function extractMetadata() {
        $tempFile = null;

        try {
            // Get the uploaded file
            $uploadedFiles = $this->request->getUploadedFile('file');
            if (!$uploadedFiles || !isset($uploadedFiles['tmp_name'])) {
                return new JSONResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
            }

            $rejection = $this->rejectUnacceptableUpload($uploadedFiles);
            if ($rejection !== null) {
                return $rejection;
            }

            // Get user and folder
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
            }
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());

            // Create temporary file for metadata extraction
            $tempFilename = 'temp_extract_' . time() . '_'
                . $this->filenameService->sanitizeUploadFilename($uploadedFiles['name']);
            $tempFile = $userFolder->newFile($tempFilename);
            $tempFile->putContent(file_get_contents($uploadedFiles['tmp_name']));

            // Extract metadata
            $metadata = $this->bookService->extractMetadataForUpload($tempFile);

            return new JSONResponse([
                'metadata' => $metadata
            ]);

        } catch (\Exception $e) {
            return $this->internalError('Could not read metadata from that file', $e);
        } finally {
            // In a finally block, not on the happy path: an exception between
            // newFile() and here used to leave the temp file sitting in the root
            // of the user's Nextcloud.
            if ($tempFile !== null) {
                try {
                    $tempFile->delete();
                } catch (\Throwable $e) {
                    $this->logger->warning('Could not remove metadata extraction temp file', [
                        'app' => 'koreader_companion',
                        'exception' => $e,
                    ]);
                }
            }
        }
    }

    /**
     * Reject anything the library cannot hold, before it is written to storage.
     *
     * Extension filtering used to live only in the browser, and
     * SUPPORTED_EXTENSIONS gated indexing rather than upload -- so the endpoint
     * accepted any file type and any size PHP would take.
     */
    private function rejectUnacceptableUpload(array $uploadedFile): ?JSONResponse {
        $extension = strtolower(pathinfo((string)($uploadedFile['name'] ?? ''), PATHINFO_EXTENSION));

        if (!in_array($extension, BookService::SUPPORTED_EXTENSIONS, true)) {
            return new JSONResponse([
                'error' => 'Unsupported file type. Supported: '
                    . implode(', ', BookService::SUPPORTED_EXTENSIONS),
            ], Http::STATUS_UNSUPPORTED_MEDIA_TYPE);
        }

        $size = (int)($uploadedFile['size'] ?? 0);
        if ($size > self::MAX_UPLOAD_BYTES) {
            return new JSONResponse([
                'error' => 'That file is larger than the ' . (self::MAX_UPLOAD_BYTES >> 20) . ' MB limit',
            ], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
        }

        return null;
    }

    /**
     * Log the detail, tell the client nothing it can use.
     *
     * These handlers used to return $e->getMessage() verbatim, which handed any
     * authenticated user absolute filesystem paths and database driver errors.
     */
    private function internalError(string $message, \Throwable $e): JSONResponse {
        $this->logger->error($message, [
            'app' => 'koreader_companion',
            'exception' => $e,
        ]);

        return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);
    }

    #[NoAdminRequired]
    public function updateMetadata($id) {
        try {
            // Try to parse raw input if request params are empty
            $rawInput = file_get_contents('php://input');
            $parsedData = [];
            if (!empty($rawInput)) {
                parse_str($rawInput, $parsedData);
            }

            // Get metadata from request, using parsed data as fallback
            $publicationYear = $this->request->getParam('publication_date', $parsedData['publication_date'] ?? '');

            // Validate publication year (must be 4-digit year or empty)
            if (!empty($publicationYear) && (!is_numeric($publicationYear) || strlen($publicationYear) !== 4 || intval($publicationYear) < 1000 || intval($publicationYear) > 2099)) {
                return new JSONResponse(['error' => 'Publication year must be a 4-digit year (1000-2099)'], Http::STATUS_BAD_REQUEST);
            }

            // Convert year to publication_date format (YYYY-MM-DD)
            $publicationDate = null;
            if (!empty($publicationYear)) {
                $publicationDate = $publicationYear . '-01-01'; // Default to January 1st
            }

            $metadata = [
                'title' => $this->request->getParam('title', $parsedData['title'] ?? ''),
                'author' => $this->request->getParam('author', $parsedData['author'] ?? ''),
                'format' => $this->request->getParam('format', $parsedData['format'] ?? ''),
                'language' => $this->request->getParam('language', $parsedData['language'] ?? ''),
                'publisher' => $this->request->getParam('publisher', $parsedData['publisher'] ?? ''),
                'publication_date' => $publicationDate,
                'description' => $this->request->getParam('description', $parsedData['description'] ?? ''),
                'tags' => $this->request->getParam('tags', $parsedData['tags'] ?? ''),
                'series' => $this->request->getParam('series', $parsedData['series'] ?? ''),
                'issue' => $this->request->getParam('issue', $parsedData['issue'] ?? ''),
                'volume' => $this->request->getParam('volume', $parsedData['volume'] ?? '')
            ];

            // Get the book file by ID
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
            }
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());

            // Validate file ID
            if (!is_numeric($id)) {
                return new JSONResponse(['error' => 'Invalid book ID'], Http::STATUS_BAD_REQUEST);
            }

            // Find file by file ID
            try {
                $targetFile = $userFolder->getById((int)$id);
                if (empty($targetFile)) {
                    return new JSONResponse(['error' => 'Book not found'], Http::STATUS_NOT_FOUND);
                }
                $targetFile = $targetFile[0]; // getById returns an array
            } catch (\OCP\Files\NotFoundException $e) {
                return new JSONResponse(['error' => 'Book not found'], Http::STATUS_NOT_FOUND);
            }

            // Update metadata
            $this->storeBookMetadata($targetFile, $metadata);

            // Check if auto-rename is enabled before renaming based on updated metadata
            $autoRename = $this->config->getValueString($user->getUID(), 'koreader_companion', 'auto_rename', 'no');
            $currentName = $targetFile->getName();

            if ($autoRename === 'yes') {
                $newName = $this->filenameService->generateStandardFilename($metadata, $currentName);
            } else {
                $newName = $currentName; // Keep current filename
            }

            if ($newName !== $currentName) {
                // Check for conflicts and resolve
                $parentFolder = $targetFile->getParent();
                $newName = $this->filenameService->resolveFilenameConflict($parentFolder, $newName);

                // Rename the file
                $targetFile->move($parentFolder->getPath() . '/' . $newName);

                // CRITICAL: Update hash mappings after rename to maintain KOReader sync
                $this->updateHashMappingAfterRename($targetFile, $user->getUID());
            }

            return new JSONResponse([]);

        } catch (\Exception $e) {
            return $this->internalError('Could not save the metadata', $e);
        }
    }

    /**
     * Extract metadata for books still marked pending, in this request.
     *
     * Nextcloud offers no way to run one specific background job on demand, so
     * the "extract now" button in the library cannot poke the queue -- it has to
     * do the work. Bounded to PENDING_BATCH_LIMIT per call and rate-limited,
     * because the cost scales with file size.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function processPending() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse($this->bookService->processPendingBooks($user->getUID()));
        } catch (\Exception $e) {
            return $this->internalError('Could not extract metadata', $e);
        }
    }

    /**
     * The position currently stored for a book.
     *
     * The reader asks for this on open rather than trusting the copy embedded in
     * the library listing: that listing is fetched once, so a device syncing
     * afterwards leaves it stale, and the reader would resume from a position that
     * has since been superseded.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function bookProgress($id) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (!is_numeric($id)) {
            return new JSONResponse(['error' => 'Invalid book ID'], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(['progress' => $this->readingProgress->find($user->getUID(), (int)$id)]);
    }

    /**
     * Highlights and notes a device uploaded for one book.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function bookAnnotations($id) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (!is_numeric($id)) {
            return new JSONResponse(['error' => 'Invalid book ID'], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse([
            'annotations' => $this->annotations->forBook($user->getUID(), (int)$id),
        ]);
    }

    /**
     * Annotation counts for a set of books, for the badge on each cover.
     *
     * Batched on purpose: reading the folder once for a page of books beats one
     * directory listing per cover.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function annotationCounts() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        $ids = array_filter(
            array_map('intval', explode(',', (string)$this->request->getParam('ids', ''))),
        );

        return new JSONResponse([
            'counts' => $this->annotations->countsFor($user->getUID(), array_values($ids)),
        ]);
    }

    /**
     * Save a reading position from the in-browser reader.
     *
     * Writes to the same table KOReader devices sync against, keyed by the same
     * document hash, so a position saved here is one a device can pull. The
     * position itself arrives as a KOReader xpointer -- the browser converts it
     * from the reader's own CFI -- because a device pulling a CFI would not
     * understand it.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function saveProgress($id) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (!is_numeric($id)) {
            return new JSONResponse(['error' => 'Invalid book ID'], Http::STATUS_BAD_REQUEST);
        }

        $progress = (string)$this->request->getParam('progress', '');
        if ($progress === '') {
            return new JSONResponse(['error' => 'Position required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $saved = $this->readingProgress->save(
                $user->getUID(),
                (int)$id,
                $progress,
                $this->request->getParam('percentage', 0),
                (string)$this->request->getParam('device', 'Nextcloud Web'),
                (string)$this->request->getParam('device_id', 'nextcloud-web')
            );
        } catch (\Exception $e) {
            return $this->internalError('Could not save your reading position', $e);
        }

        if (!$saved) {
            // No document hash means no device could ever match this book, so
            // storing the position would put it somewhere nothing reads from.
            return new JSONResponse(
                ['error' => 'This book has no sync identity yet'],
                Http::STATUS_CONFLICT
            );
        }

        return new JSONResponse([]);
    }

    /**
     * Raw file bytes for the in-browser reader.
     *
     * The OPDS download route needs Basic Auth, and /f/{fileId} answers with a
     * redirect into the Files app, so neither can be fetched from the web UI.
     * This one is plain session auth and hands back the file itself.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function bookFile($id) {
        if (!is_numeric($id)) {
            return new JSONResponse(['error' => 'Invalid book ID'], Http::STATUS_BAD_REQUEST);
        }

        $book = $this->bookService->getBookById((int)$id);
        if (!$book) {
            return new JSONResponse(['error' => 'Book not found'], Http::STATUS_NOT_FOUND);
        }

        return $this->bookService->downloadBook($book, $book['format']);
    }

    /**
     * Delete a book from the library
     */
    #[NoAdminRequired]
    public function deleteBook($id) {
        try {
            // Get the book file by ID
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
            }
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());

            // Validate file ID
            if (!is_numeric($id)) {
                return new JSONResponse(['error' => 'Invalid book ID'], Http::STATUS_BAD_REQUEST);
            }

            // Find file by file ID
            try {
                $targetFile = $userFolder->getById((int)$id);
                if (empty($targetFile)) {
                    return new JSONResponse(['error' => 'Book not found'], Http::STATUS_NOT_FOUND);
                }
                $targetFile = $targetFile[0]; // getById returns an array
            } catch (\OCP\Files\NotFoundException $e) {
                return new JSONResponse(['error' => 'Book not found'], Http::STATUS_NOT_FOUND);
            }

            // Remove from database first
            $this->bookService->removeBookFromDatabase($targetFile);

            // Delete the file
            $targetFile->delete();

            return new JSONResponse([]);

        } catch (\Exception $e) {
            return $this->internalError('Could not delete that book', $e);
        }
    }

    /**
     * Store book metadata in database
     */
    private function storeBookMetadata($file, $metadata) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return;
        }

        $userId = $user->getUID();
        $fileId = $file->getId();
        $filePath = $file->getPath();
        $currentTime = date('Y-m-d H:i:s');

        try {
            // Check if metadata already exists
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
                ->executeQuery();

            $existingId = $result->fetchOne();
            $result->closeCursor();

            if ($existingId) {
                // Update existing metadata
                $updateQb = $this->db->getQueryBuilder();
                $updateQb->update('koreader_metadata')
                    ->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter($existingId)));

                // Update all provided metadata fields
                foreach ($metadata as $key => $value) {
                    if (in_array($key, ['title', 'author', 'description', 'language', 'publisher', 'publication_date', 'subject', 'series', 'issue', 'volume', 'tags'])) {
                        $updateQb->set($key, $updateQb->createNamedParameter($value ?: null));
                    }
                }
                
                $updateQb->set('file_path', $updateQb->createNamedParameter($filePath))
                    ->set('updated_at', $updateQb->createNamedParameter($currentTime))
                    // This row now holds real metadata -- the user's own, typed
                    // into the upload form. Marking it done both stops the UI
                    // claiming it is still processing and stops the queued
                    // ExtractMetadataJob overwriting those values on the next
                    // cron run (BookService::indexFile skips done rows).
                    ->set('indexing_state', $updateQb->createNamedParameter(BookService::STATE_DONE));

                $updateQb->executeStatement();
            } else {
                // Insert new metadata
                $insertQb = $this->db->getQueryBuilder();
                $insertQb->insert('koreader_metadata')
                    ->values([
                        'user_id' => $insertQb->createNamedParameter($userId),
                        'file_id' => $insertQb->createNamedParameter($fileId),
                        'file_path' => $insertQb->createNamedParameter($filePath),
                        'title' => $insertQb->createNamedParameter($metadata['title'] ?? null),
                        'author' => $insertQb->createNamedParameter($metadata['author'] ?? null),
                        'description' => $insertQb->createNamedParameter($metadata['description'] ?? null),
                        'language' => $insertQb->createNamedParameter($metadata['language'] ?? null),
                        'publisher' => $insertQb->createNamedParameter($metadata['publisher'] ?? null),
                        'publication_date' => $insertQb->createNamedParameter($metadata['publication_date'] ?? null),
                        'subject' => $insertQb->createNamedParameter($metadata['subject'] ?? null),
                        'series' => $insertQb->createNamedParameter($metadata['series'] ?? null),
                        'issue' => $insertQb->createNamedParameter($metadata['issue'] ?? null),
                        'volume' => $insertQb->createNamedParameter($metadata['volume'] ?? null),
                        'tags' => $insertQb->createNamedParameter($metadata['tags'] ?? null),
                        'indexing_state' => $insertQb->createNamedParameter(BookService::STATE_DONE),
                        'created_at' => $insertQb->createNamedParameter($currentTime),
                        'updated_at' => $insertQb->createNamedParameter($currentTime),
                    ]);

                $insertQb->executeStatement();
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to store metadata for file', [
                'file_path' => $filePath,
                'exception' => $e
            ]);
        }
    }

    /**
     * Find a file by its path within a folder (recursive search)
     */
    private function findFileByPath($folder, $targetPath) {
        $files = $folder->getDirectoryListing();

        foreach ($files as $file) {
            if ($file->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                // Recursively search subfolders
                $found = $this->findFileByPath($file, $targetPath);
                if ($found) {
                    return $found;
                }
            } else {
                // Check if this is the file we're looking for
                $relativePath = $folder->getRelativePath($file->getPath());
                $fileName = $file->getName();
                $targetBasename = basename($targetPath);

                // Primary checks
                if ($relativePath === $targetPath || $fileName === $targetBasename || $file->getPath() === $targetPath) {
                    return $file;
                }

                // Fallback: normalize paths for comparison (handle encoding issues)
                $normalizedRelativePath = $this->normalizePath($relativePath);
                $normalizedTargetPath = $this->normalizePath($targetPath);
                $normalizedFileName = $this->normalizePath($fileName);
                $normalizedTargetBasename = $this->normalizePath($targetBasename);

                if ($normalizedRelativePath === $normalizedTargetPath ||
                    $normalizedFileName === $normalizedTargetBasename) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Normalize file path for comparison (handle common encoding issues)
     */
    private function normalizePath($path) {
        // Replace backticks with spaces (common encoding issue)
        $path = str_replace('`', ' ', $path);
        // Normalize multiple spaces to single space
        $path = preg_replace('/\s+/', ' ', $path);
        // Trim whitespace
        return trim($path);
    }

    /**
     * Update filename hash mapping when a file is renamed
     *
     * This is critical for KOReader sync - when files are renamed, the filename hash changes
     * and KOReader sync will break unless we update the hash mapping table.
     */
    private function updateHashMappingAfterRename($file, $userId) {
        try {
            $fileId = $file->getId();

            // Get the metadata ID for this file
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
                ->executeQuery();

            $metadataId = $result->fetchOne();
            $result->closeCursor();

            if (!$metadataId) {
                $this->logger->warning('No metadata found for file after rename', [
                    'file_name' => $file->getName()
                ]);
                return;
            }

            // Generate new filename hash
            $newFilenameHash = $this->hashGenerator->generateFilenameHashFromNode($file);
            if (!$newFilenameHash) {
                $this->logger->error('Failed to generate new filename hash after rename', [
                    'file_name' => $file->getName()
                ]);
                return;
            }

            // Update the filename hash mapping (if it exists)
            $updateQb = $this->db->getQueryBuilder();
            $updatedRows = $updateQb->update('koreader_hash_mapping')
                ->set('document_hash', $updateQb->createNamedParameter($newFilenameHash))
                ->where($updateQb->expr()->eq('user_id', $updateQb->createNamedParameter($userId)))
                ->andWhere($updateQb->expr()->eq('metadata_id', $updateQb->createNamedParameter($metadataId)))
                ->andWhere($updateQb->expr()->eq('hash_type', $updateQb->createNamedParameter('filename')))
                ->executeStatement();

            if ($updatedRows > 0) {
                $this->logger->info('Updated filename hash mapping after rename', [
                    'file_name' => $file->getName(),
                    'new_hash' => $newFilenameHash
                ]);
            } else {
                $this->logger->info('No filename hash mapping to update after rename', [
                    'file_name' => $file->getName()
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to update hash mapping after rename', [
                'exception' => $e
            ]);
        }
    }
}
