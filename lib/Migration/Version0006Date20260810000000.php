<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Schema cleanup: drop the orphaned tracking table and the redundant indexes.
 *
 * Everything here goes through the schema wrapper rather than raw SQL, so it is
 * prefix-aware and portable -- the mistakes that made Version0004 and
 * Version0005 no-ops.
 */
class Version0006Date20260810000000 extends SimpleMigrationStep {

    /**
     * Indexes on koreader_metadata that duplicate another index on the same
     * columns. Confirmed against a real MariaDB 11 schema after a fresh
     * install; each pair below covers identical columns in identical order, so
     * the second one only costs write throughput.
     *
     *   idx_ebooks_pagination    == idx_ebooks_search_title  (user_id, title)
     *   idx_ebooks_search_author == meta_user_author_idx      (user_id, author)
     *   idx_ebooks_date          == meta_user_pubdate_idx     (user_id, publication_date)
     *   idx_ebooks_series        == meta_series_order_idx     (user_id, series, series_index)
     *   meta_user_series_idx     is a strict prefix of meta_series_order_idx
     */
    private const REDUNDANT_INDEXES = [
        'idx_ebooks_pagination',
        'idx_ebooks_search_author',
        'idx_ebooks_date',
        'idx_ebooks_series',
        'meta_user_series_idx',
    ];

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('koreader_file_tracking')) {
            $schema->dropTable('koreader_file_tracking');
            $output->info('Dropped orphaned table koreader_file_tracking');
            $changed = true;
        }

        if ($schema->hasTable('koreader_metadata')) {
            $table = $schema->getTable('koreader_metadata');
            foreach (self::REDUNDANT_INDEXES as $index) {
                if ($table->hasIndex($index)) {
                    $table->dropIndex($index);
                    $output->info('Dropped redundant index ' . $index);
                    $changed = true;
                }
            }
        }

        return $changed ? $schema : null;
    }
}
