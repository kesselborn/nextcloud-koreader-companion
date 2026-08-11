<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add indexing_state, so a freshly uploaded book can be shown as pending.
 *
 * Metadata extraction moved out of the upload's database transaction and into a
 * background job. The book row is now written immediately from data already on
 * the node (name, size, mtime) and marked 'pending'; the job fills in the real
 * metadata and flips it to 'done'. The UI shows the pending state rather than
 * quietly displaying a filename as if it were the title.
 */
class Version0008Date20260811100000 extends SimpleMigrationStep {

    public const STATE_PENDING = 'pending';
    public const STATE_DONE = 'done';

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('koreader_metadata')) {
            return null;
        }

        $table = $schema->getTable('koreader_metadata');
        if ($table->hasColumn('indexing_state')) {
            return null;
        }

        // Existing rows already have real metadata, so 'done' is the correct
        // default -- nothing needs re-indexing on upgrade.
        $table->addColumn('indexing_state', Types::STRING, [
            'notnull' => true,
            'length' => 16,
            'default' => self::STATE_DONE,
            'comment' => 'pending while a background job still has to extract metadata',
        ]);

        if (!$table->hasIndex('meta_state_idx')) {
            $table->addIndex(['user_id', 'indexing_state'], 'meta_state_idx');
        }

        $output->info('Added koreader_metadata.indexing_state');

        return $schema;
    }
}
