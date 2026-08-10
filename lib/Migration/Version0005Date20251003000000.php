<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Historical no-op.
 *
 * This step used to run:
 *
 *     DROP TABLE IF EXISTS `oc_koreader_file_tracking`
 *
 * which never worked anywhere:
 *
 *  - On PostgreSQL the backticks are a syntax error ("syntax error at or near
 *    `"), so the statement threw and the surrounding try/catch swallowed it.
 *  - The `oc_` prefix was hardcoded, so it could never match an installation
 *    using a custom dbtableprefix.
 *  - Verified on a fresh Nextcloud 34.0.2 install: koreader_file_tracking was
 *    still present afterwards on both PostgreSQL and MariaDB.
 *
 * The body is kept empty rather than deleted so the migration version stays in
 * oc_migrations for installs that already recorded it. Version0006 performs the
 * drop declaratively, which is the supported way.
 */
class Version0005Date20251003000000 extends SimpleMigrationStep {

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $output->info('Superseded by Version0006 (see class docblock); nothing to do.');
    }
}
