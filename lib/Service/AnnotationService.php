<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service;

use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Reads KOReader highlights and notes that a device uploaded over WebDAV.
 *
 * KOReader itself cannot send annotations anywhere -- the sync protocol has no
 * field for them and every built-in exporter is one-way and lossy. What it can
 * do, via AnnotationSync.koplugin, is upload one JSON file per book to cloud
 * storage, and Nextcloud is reachable as WebDAV. So the device writes files into
 * a folder and this reads them; there is no protocol between us.
 *
 * The files are named after the document hash, which is the same partial MD5
 * the sync protocol uses as its document id, so a file maps to a book through
 * the mapping table we already keep. Records are the device's own sidecar
 * entries verbatim, `pos0`/`pos1` included -- which is what makes exact
 * placement in the web reader possible at all. See docs/koreader-sidecar.md.
 */
class AnnotationService {

    /**
     * Where devices upload. Derived from the library folder rather than
     * configured: that folder is already a setting we own, and a second
     * user-supplied path would be a second chance at the traversal bug
     * SettingsController::setFolder had.
     */
    public const FOLDER_NAME = '.koreader-annotations';

    /**
     * A device writes tens of records per book. Anything past this is not a
     * reading history, so stop rather than hand the browser an unbounded list.
     */
    private const MAX_ANNOTATIONS_PER_BOOK = 2000;

    /** Above this a file is not annotations, whatever its name says. */
    private const MAX_FILE_BYTES = 4 * 1024 * 1024;

    /** json_decode's default is 512; annotation records are two levels deep. */
    private const JSON_DEPTH = 8;

    public function __construct(
        private IRootFolder $rootFolder,
        private IUserConfig $config,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * How many annotations each of these books has.
     *
     * Batched deliberately: the library shows a page of books at a time and a
     * per-book request would mean one directory listing each.
     *
     * @param int[] $fileIds
     * @return array<int, int> file id => count, absent when a book has none
     */
    public function countsFor(string $userId, array $fileIds): array {
        if ($fileIds === []) {
            return [];
        }

        $files = $this->annotationFiles($userId);
        if ($files === []) {
            return [];
        }

        $wanted = array_flip(array_map('intval', $fileIds));
        $counts = [];

        foreach ($files as $key => $file) {
            $fileId = $this->resolveFileId($userId, $key);
            if ($fileId === null || !isset($wanted[$fileId])) {
                continue;
            }

            $records = $this->readRecords($file);
            if ($records !== []) {
                $counts[$fileId] = count($records);
            }
        }

        return $counts;
    }

    /**
     * Every annotation for one book, newest chapter order preserved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBook(string $userId, int $fileId): array {
        foreach ($this->annotationFiles($userId) as $key => $file) {
            if ($this->resolveFileId($userId, $key) !== $fileId) {
                continue;
            }

            $records = array_map(
                fn (array $record): array => $this->normalize($record),
                $this->readRecords($file)
            );

            // Reading order, which is what a reader expects a list of their own
            // highlights to be in. `pageno` is the device's own page number, so
            // it already reflects the order they were read in.
            usort($records, function (array $a, array $b): int {
                return [$a['pageno'], $a['pos0']] <=> [$b['pageno'], $b['pos0']];
            });

            return $records;
        }

        return [];
    }

    /**
     * The uploaded files, keyed by the filename with `.json` removed.
     *
     * The library folder itself is read, plus `.koreader-annotations/` if it
     * exists. The folder is chosen in the plugin's own picker on the device, and
     * picking the library folder is the obvious thing to do there -- so that is
     * the case to support, not a subfolder the user has to think about.
     *
     * On a name collision the library folder wins, because it is read last: if
     * the same hash exists in both, the one the device is actively writing to is
     * the one to trust, not a leftover in a folder nobody synced to since.
     *
     * @return array<string, \OCP\Files\File>
     */
    private function annotationFiles(string $userId): array {
        $files = [];

        foreach ($this->annotationFolders($userId) as $folder) {
            foreach ($folder->getDirectoryListing() as $node) {
                $name = $node->getName();

                if (!$node instanceof \OCP\Files\File || !str_ends_with($name, '.json')) {
                    continue;
                }

                // The plugin writes progress next to the annotations. Progress is
                // kosync's job and already works; a second source for the same
                // position is how positions start disagreeing.
                if (str_ends_with($name, '.progress.json')) {
                    continue;
                }

                $files[substr($name, 0, -strlen('.json'))] = $node;
            }
        }

        return $files;
    }

    /**
     * Where to look, most deliberate first.
     *
     * Only these two: a recursive search would turn every request into a walk of
     * the whole library, and would happily pick up unrelated JSON a user keeps
     * among their books.
     *
     * @return \OCP\Files\Folder[]
     */
    private function annotationFolders(string $userId): array {
        $library = $this->libraryFolder($userId);
        if ($library === null) {
            return [];
        }

        $folders = [];

        try {
            $dedicated = $library->get(self::FOLDER_NAME);
            if ($dedicated instanceof Folder) {
                $folders[] = $dedicated;
            }
        } catch (\Throwable $e) {
            // Never created, which is fine -- the library itself is still read.
        }

        $folders[] = $library;

        return $folders;
    }

    private function libraryFolder(string $userId): ?Folder {
        try {
            $folderName = $this->config->getValueString($userId, 'koreader_companion', 'folder', 'eBooks');
            $library = $this->rootFolder->getUserFolder($userId)->get($folderName);

            return $library instanceof Folder ? $library : null;
        } catch (\Throwable $e) {
            // Not configured yet, or the folder is gone. Both mean "no
            // annotations", not an error worth surfacing.
            return null;
        }
    }

    /**
     * Which book a file belongs to.
     *
     * AnnotationSync names files after `util.partialMD5`, which is what
     * DocumentHashGenerator computes and what the sync protocol sends as
     * `document` -- so the default case is an indexed lookup. Its "use filename
     * instead of hash" option writes `book.epub.json` instead, hence the
     * fallback.
     */
    private function resolveFileId(string $userId, string $key): ?int {
        if (preg_match('/^[0-9a-f]{32}$/', $key) === 1) {
            return $this->fileIdForHash($userId, $key);
        }

        return $this->fileIdForName($userId, $key);
    }

    private function fileIdForHash(string $userId, string $hash): ?int {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('em.file_id')
            ->from('koreader_hash_mapping', 'hm')
            ->innerJoin('hm', 'koreader_metadata', 'em', 'hm.metadata_id = em.id')
            ->where($qb->expr()->eq('hm.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('hm.document_hash', $qb->createNamedParameter($hash)))
            ->setMaxResults(1)
            ->executeQuery();
        $fileId = $result->fetchOne();
        $result->closeCursor();

        return $fileId === false || $fileId === null ? null : (int)$fileId;
    }

    private function fileIdForName(string $userId, string $name): ?int {
        $library = $this->libraryFolder($userId);
        if ($library === null) {
            return null;
        }

        // The name is the book's own filename, so ask the library for it rather
        // than pattern-matching paths.
        try {
            foreach ($library->search($name) as $node) {
                if ($node->getName() === $name) {
                    return $node->getId();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Could not resolve an annotation file by name', [
                'app' => 'koreader_companion',
                'name' => $name,
                'exception' => $e,
            ]);
        }

        return null;
    }

    /**
     * Decode one uploaded file into a flat list of records.
     *
     * Two shapes exist in the wild and both are cheap to accept:
     * AnnotationSync writes an object keyed by `pos0||pos1`, highlightsync a
     * bare array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readRecords(\OCP\Files\File $file): array {
        try {
            if ($file->getSize() > self::MAX_FILE_BYTES) {
                $this->logger->warning('Ignoring an oversized annotation file', [
                    'app' => 'koreader_companion',
                    'name' => $file->getName(),
                    'size' => $file->getSize(),
                ]);

                return [];
            }

            $decoded = json_decode($file->getContent(), true, self::JSON_DEPTH);
            if (!is_array($decoded)) {
                return [];
            }

            $records = [];
            foreach ($decoded as $record) {
                if (!is_array($record) || !isset($record['pos0'])) {
                    continue;
                }

                $records[] = $record;

                if (count($records) >= self::MAX_ANNOTATIONS_PER_BOOK) {
                    break;
                }
            }

            return $records;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read an annotation file', [
                'app' => 'koreader_companion',
                'name' => $file->getName(),
                'exception' => $e,
            ]);

            return [];
        }
    }

    /**
     * One device record, reduced to what the UI needs.
     *
     * `drawer` is what distinguishes the three kinds: absent means a bookmark,
     * present means a highlight, and a `note` on top of that means a highlight
     * with a comment. `note` is genuinely optional -- a sidecar of nothing but
     * bare highlights has no such field anywhere.
     */
    private function normalize(array $record): array {
        $text = trim((string)($record['text'] ?? ''));
        $note = trim((string)($record['note'] ?? ''));
        $drawer = trim((string)($record['drawer'] ?? ''));

        return [
            'pos0' => (string)($record['pos0'] ?? ''),
            'pos1' => (string)($record['pos1'] ?? ''),
            'text' => $text,
            'note' => $note === '' ? null : $note,
            'chapter' => trim((string)($record['chapter'] ?? '')) ?: null,
            'datetime' => trim((string)($record['datetime'] ?? '')) ?: null,
            'pageno' => isset($record['pageno']) ? (int)$record['pageno'] : 0,
            'color' => trim((string)($record['color'] ?? '')) ?: null,
            'type' => $drawer === '' ? 'bookmark' : 'highlight',
        ];
    }
}
