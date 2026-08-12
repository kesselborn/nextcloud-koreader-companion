<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service;

use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Writes reading progress into the same table KOReader devices sync against.
 *
 * The web reader is just another device as far as the sync protocol is
 * concerned, so its position goes to the same place, keyed by the same document
 * hash. That is the whole point: a position saved in the browser has to be
 * findable by a Kobo, and vice versa.
 *
 * Progress is stored as a KOReader xpointer, converted from the reader's CFI in
 * the browser. A device that pulls it gets something its own engine understands
 * rather than a CFI it would fail to parse.
 */
class ReadingProgressService {

    /**
     * Columns are varchar(100); a longer device name would fail the insert. Same
     * ceiling the sync endpoint applies.
     */
    private const DEVICE_MAX_LENGTH = 100;

    public function __construct(
        private IDBConnection $db,
        private IRootFolder $rootFolder,
        private DocumentHashGenerator $hashGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Clamp and round a percentage for storage.
     *
     * The column held varchar(10) until recently and a real client sends full
     * float precision ('0.6333333333333333'), which overflowed it and turned
     * every device sync into an HTTP 500. Six decimals is finer than any reader's
     * page granularity.
     */
    public function normalizePercentage($percentage): string {
        $value = is_numeric($percentage) ? (float)$percentage : 0.0;
        $value = max(0.0, min(1.0, $value));

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }

    public function normalizeDevice($device): string {
        return mb_substr((string)$device, 0, self::DEVICE_MAX_LENGTH);
    }

    /**
     * Store a position for one of this user's books.
     *
     * @return bool False when the book has no usable document hash, which means
     *              no device could ever match it anyway.
     */
    public function save(
        string $userId,
        int $fileId,
        string $progress,
        $percentage,
        string $device,
        string $deviceId,
    ): bool {
        $documentHash = $this->documentHashFor($userId, $fileId);
        if ($documentHash === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $percentage = $this->normalizePercentage($percentage);
        $device = $this->normalizeDevice($device);
        $deviceId = $this->normalizeDevice($deviceId);

        $existing = $this->db->getQueryBuilder();
        $result = $existing->select('id')
            ->from('koreader_sync_progress')
            ->where($existing->expr()->eq('user_id', $existing->createNamedParameter($userId)))
            ->andWhere($existing->expr()->eq('document_hash', $existing->createNamedParameter($documentHash)))
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        $qb = $this->db->getQueryBuilder();

        if ($row) {
            $qb->update('koreader_sync_progress')
                ->set('progress', $qb->createNamedParameter($progress))
                ->set('percentage', $qb->createNamedParameter($percentage))
                ->set('device', $qb->createNamedParameter($device))
                ->set('device_id', $qb->createNamedParameter($deviceId))
                ->set('updated_at', $qb->createNamedParameter($now))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'])))
                ->executeStatement();
        } else {
            $qb->insert('koreader_sync_progress')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'document_hash' => $qb->createNamedParameter($documentHash),
                    'progress' => $qb->createNamedParameter($progress),
                    'percentage' => $qb->createNamedParameter($percentage),
                    'device' => $qb->createNamedParameter($device),
                    'device_id' => $qb->createNamedParameter($deviceId),
                    'updated_at' => $qb->createNamedParameter($now),
                ])
                ->executeStatement();
        }

        return true;
    }

    /**
     * The position currently stored for a book, whoever wrote it.
     *
     * @return ?array{progress: string, percentage: float, device: string, updated_at: string}
     */
    public function find(string $userId, int $fileId): ?array {
        $documentHash = $this->documentHashFor($userId, $fileId);
        if ($documentHash === null) {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('progress', 'percentage', 'device', 'updated_at')
            ->from('koreader_sync_progress')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('document_hash', $qb->createNamedParameter($documentHash)))
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return null;
        }

        return [
            // Percent, matching what the book listing exposes.
            'percentage' => (float)$row['percentage'] * 100,
            'progress_data' => (string)$row['progress'],
            'device' => (string)$row['device'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    /**
     * The document hash a device would use for this file.
     *
     * Prefers an existing mapping so the browser and a device agree. If the book
     * has never been mapped -- nothing has synced it yet -- the binary hash is
     * computed and recorded, so a device that later opens the same file finds the
     * progress already waiting rather than starting from zero.
     */
    private function documentHashFor(string $userId, int $fileId): ?string {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('hm.document_hash')
            ->from('koreader_hash_mapping', 'hm')
            ->innerJoin('hm', 'koreader_metadata', 'em', 'hm.metadata_id = em.id')
            ->where($qb->expr()->eq('hm.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('em.file_id', $qb->createNamedParameter($fileId)))
            // Binary first: it survives a rename, which the filename hash does not.
            ->orderBy('hm.hash_type', 'ASC')
            ->setMaxResults(1)
            ->executeQuery();
        $hash = $result->fetchOne();
        $result->closeCursor();

        if ($hash) {
            return (string)$hash;
        }

        return $this->createMappingFor($userId, $fileId);
    }

    private function createMappingFor(string $userId, int $fileId): ?string {
        try {
            $nodes = $this->rootFolder->getUserFolder($userId)->getById($fileId);
            if ($nodes === []) {
                return null;
            }

            $hash = $this->hashGenerator->generateBinaryHashFromNode($nodes[0]);
            if (!$hash) {
                return null;
            }

            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
                ->executeQuery();
            $metadataId = $result->fetchOne();
            $result->closeCursor();

            if (!$metadataId) {
                return null;
            }

            $insert = $this->db->getQueryBuilder();
            $insert->insert('koreader_hash_mapping')
                ->values([
                    'user_id' => $insert->createNamedParameter($userId),
                    'document_hash' => $insert->createNamedParameter($hash),
                    'hash_type' => $insert->createNamedParameter('binary'),
                    'metadata_id' => $insert->createNamedParameter((int)$metadataId),
                    'created_at' => $insert->createNamedParameter(date('Y-m-d H:i:s')),
                ])
                ->executeStatement();

            return $hash;
        } catch (\Throwable $e) {
            // A duplicate mapping is a race, not a failure: another request created
            // it, so the hash is still the right answer.
            $this->logger->debug('Could not create a hash mapping for web progress', [
                'app' => 'koreader_companion',
                'fileId' => $fileId,
                'exception' => $e,
            ]);

            return null;
        }
    }
}
