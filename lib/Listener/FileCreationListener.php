<?php
namespace OCA\KoreaderCompanion\Listener;

use OCA\KoreaderCompanion\BackgroundJob\ExtractMetadataJob;
use OCA\KoreaderCompanion\Service\BookService;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<NodeCreatedEvent|NodeWrittenEvent>
 */
class FileCreationListener implements IEventListener {

    public function __construct(
        private BookService $bookService,
        private IUserConfig $config,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof NodeCreatedEvent || $event instanceof NodeWrittenEvent)) {
            return;
        }

        $node = $event->getNode();

        if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
            return;
        }

        if (!$this->isEbookInBooksFolder($node)) {
            return;
        }

        $userId = $this->extractUserIdFromPath($node->getPath());
        if (!$userId) {
            return;
        }

        // Two steps on purpose.
        //
        // This listener runs inside the transaction Nextcloud opened for the
        // write. Reading the file here would read oc_filecache, which that same
        // transaction has just written, and Nextcloud's "dirty table reads"
        // assertion rejects exactly that -- with debug enabled every upload threw
        // and metadata was silently never stored.
        //
        // So: record the book immediately using only what is already on the node
        // (no file read, no filecache query), then queue the extraction. The row
        // is marked pending until the job runs, and the UI shows that rather than
        // presenting a filename as if it were a title.
        try {
            $this->bookService->markFilePending($node, $userId);
            $this->jobList->add(ExtractMetadataJob::class, [
                'fileId' => $node->getId(),
                'userId' => $userId,
            ]);
        } catch (\Throwable $e) {
            // An upload must never fail because we could not index it.
            $this->logger->error('Could not queue metadata extraction', [
                'app' => 'koreader_companion',
                'path' => $node->getPath(),
                'exception' => $e,
            ]);
        }
    }

    private function isEbookInBooksFolder($node): bool {
        $path = $node->getPath();

        // Extract user ID from path to get their configured folder
        $userId = $this->extractUserIdFromPath($path);
        if (!$userId) {
            return false;
        }

        $folderName = $this->config->getValueString($userId, 'koreader_companion', 'folder', 'eBooks');

        if (strpos($path, "/files/$folderName/") === false) {
            return false;
        }

        $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
        return in_array($extension, BookService::SUPPORTED_EXTENSIONS, true);
    }

    private function extractUserIdFromPath(string $path): ?string {
        if (preg_match('/^\/([^\/]+)\/files\//', $path, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
