<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop the unused cover_image column.
 *
 * It was declared in Version0001 and never read or written by any code -- covers
 * were always extracted on demand, and since the move to Nextcloud's preview
 * providers they are cached in preview storage instead. Confirmed empty on live
 * data before removal.
 *
 * Deliberately left in place for now: binary_hash and filename_hash. They are
 * also always NULL -- the hashes the sync API actually uses live in
 * koreader_hash_mapping -- but GenerateBookHashesCommand still selects and
 * updates them, and its "needs hashes" predicate is `binary_hash IS NULL`, which
 * is therefore always true, so the command reprocesses the entire library on
 * every run. Dropping the columns means rewriting that predicate against
 * koreader_hash_mapping first; see tasks.md.
 */
class Version0007Date20260811000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('koreader_metadata')) {
            return null;
        }

        $table = $schema->getTable('koreader_metadata');
        if (!$table->hasColumn('cover_image')) {
            return null;
        }

        $table->dropColumn('cover_image');
        $output->info('Dropped unused column koreader_metadata.cover_image');

        return $schema;
    }
}
