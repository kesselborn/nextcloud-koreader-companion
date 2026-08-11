<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Widen koreader_sync_progress.percentage, which was too small for real clients.
 *
 * The column was varchar(10). KOReader sends the reading position as a raw
 * float, and a real device sends full precision -- '0.6333333333333333' is 18
 * characters. Every such sync failed with
 *
 *   SQLSTATE[22001]: String data, right truncated:
 *   value too long for type character varying(10)
 *
 * which surfaced as HTTP 500 on PUT /sync/syncs/progress. Reading progress from
 * an actual device was therefore never stored, while the integration suite passed
 * throughout because it sends '0.25'.
 *
 * Widened rather than converted to a float column on purpose: PostgreSQL will not
 * cast varchar to double precision without an explicit USING clause, which
 * Doctrine does not emit, so a type change would break the migration on exactly
 * the database this app is developed against. The controller now also normalises
 * the value before storing it, so the width is a backstop rather than the fix.
 */
class Version0009Date20260812000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('koreader_sync_progress')) {
            return null;
        }

        $table = $schema->getTable('koreader_sync_progress');
        if (!$table->hasColumn('percentage')) {
            return null;
        }

        $column = $table->getColumn('percentage');
        if ($column->getLength() >= 32) {
            return null;
        }

        $column->setLength(32);

        return $schema;
    }
}
