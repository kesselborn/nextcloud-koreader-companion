<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\BackgroundJob;

use OCA\KoreaderCompanion\Service\BookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Extract metadata for one uploaded book, outside the upload's transaction.
 *
 * The file event listener used to do this inline. That put a file read inside the
 * transaction Nextcloud had opened for the write, which its "dirty table reads"
 * assertion rejects: with debug enabled every upload threw and metadata was
 * silently never written. Doing the work here also keeps large EPUBs and PDFs
 * from holding the upload request open.
 *
 * The trade-off is visible latency -- metadata appears when cron next runs -- so
 * the row is created immediately in a 'pending' state and the UI says so rather
 * than showing a filename as though it were a title.
 */
class ExtractMetadataJob extends QueuedJob {

    public function __construct(
        ITimeFactory $time,
        private IRootFolder $rootFolder,
        private BookService $bookService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    /**
     * @param array{fileId?: int, userId?: string} $argument
     */
    protected function run($argument): void {
        $fileId = (int)($argument['fileId'] ?? 0);
        $userId = (string)($argument['userId'] ?? '');

        if ($fileId <= 0 || $userId === '') {
            $this->logger->warning('ExtractMetadataJob called with an incomplete argument', [
                'app' => 'koreader_companion',
                'argument' => $argument,
            ]);
            return;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);

            if ($nodes === []) {
                // Deleted or moved out of reach between upload and cron. The
                // delete listener removes the row, so there is nothing to do.
                $this->logger->debug('Skipping metadata extraction for a file that is gone', [
                    'app' => 'koreader_companion',
                    'fileId' => $fileId,
                ]);
                return;
            }

            $this->bookService->indexFile($nodes[0], $userId);
        } catch (NotFoundException $e) {
            $this->logger->debug('User folder unavailable for metadata extraction', [
                'app' => 'koreader_companion',
                'userId' => $userId,
            ]);
        } catch (\Throwable $e) {
            // Never rethrow: a single unreadable book must not stall the queue
            // for every other job.
            $this->logger->error('Background metadata extraction failed', [
                'app' => 'koreader_companion',
                'fileId' => $fileId,
                'userId' => $userId,
                'exception' => $e,
            ]);
        }
    }
}
