<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service;

use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
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

    /**
     * Books per counts request. The library asks for a page at a time; a caller
     * asking about ten thousand is not the library.
     */
    private const MAX_BOOKS_PER_REQUEST = 250;

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
        $fileIds = array_slice(array_unique(array_map('intval', $fileIds)), 0, self::MAX_BOOKS_PER_REQUEST);
        if ($fileIds === []) {
            return [];
        }

        // Resolve the names to look for *first*, in two queries, then read only
        // the files that match one. The reverse -- resolving every file in the
        // folder -- cost a query or a library-wide search per file, which is
        // linear in however many stray .json files happen to sit among the books:
        // measured at 1.4 ms each, so a thousand of them turned one request into
        // 1.4 s. Now a file nobody asked about costs a hash-map lookup.
        $names = $this->namesFor($userId, $fileIds);
        if ($names === []) {
            return [];
        }

        $counts = [];

        foreach ($this->annotationFiles($userId) as $key => $file) {
            $fileId = $names[$key] ?? null;
            if ($fileId === null) {
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
        $names = $this->namesFor($userId, [$fileId]);
        if ($names === []) {
            return [];
        }

        foreach ($this->annotationFiles($userId) as $key => $file) {
            if (($names[$key] ?? null) !== $fileId) {
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
     * The file names that would belong to these books: name => file id.
     *
     * Two shapes, because AnnotationSync offers two. By default it names files
     * after `util.partialMD5`, which is the document id the sync protocol uses
     * and is already in our mapping table. Its "use filename instead of hash"
     * option writes `book.epub.json` instead, hence the second query.
     *
     * Both are batched and indexed. Doing this per file in the folder instead --
     * which is what this did first -- meant a query or a library-wide search for
     * every stray .json among the books.
     *
     * @param int[] $fileIds
     * @return array<string, int>
     */
    private function namesFor(string $userId, array $fileIds): array {
        if ($fileIds === []) {
            return [];
        }

        $names = [];

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('hm.document_hash', 'em.file_id')
            ->from('koreader_hash_mapping', 'hm')
            ->innerJoin('hm', 'koreader_metadata', 'em', 'hm.metadata_id = em.id')
            ->where($qb->expr()->eq('hm.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('em.file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeQuery();
        foreach ($result->fetchAll() as $row) {
            $names[(string)$row['document_hash']] = (int)$row['file_id'];
        }
        $result->closeCursor();

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('file_id', 'file_path')
            ->from('koreader_metadata')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeQuery();
        foreach ($result->fetchAll() as $row) {
            $basename = basename((string)$row['file_path']);
            if ($basename !== '') {
                $names[$basename] = (int)$row['file_id'];
            }
        }
        $result->closeCursor();

        return $names;
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
