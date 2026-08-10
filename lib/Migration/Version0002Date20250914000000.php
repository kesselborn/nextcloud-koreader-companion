<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\Config\IUserConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * One-time cleanup of KOReader sync password hashes that are not MD5.
 *
 * An older version stored bcrypt hashes here. The KOReader protocol sends MD5,
 * so those values can never authenticate and are cleared; affected users set the
 * password again in the web UI.
 *
 * Uses IUserConfig rather than querying oc_preferences directly. The raw query
 * this replaced is unsupported for apps and increasingly wrong: NC 31+ gave the
 * config tables typed and lazy columns, and values may be stored in a form that
 * a plain SELECT on configvalue misreads.
 */
class Version0002Date20250914000000 extends SimpleMigrationStep {

    private const APP = 'koreader_companion';
    private const KEY = 'koreader_sync_password';

    public function __construct(
        private IUserConfig $userConfig,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $output->info('Checking for existing KOReader sync passwords...');

        $cleared = 0;
        foreach ($this->userConfig->getValuesByUsers(self::APP, self::KEY) as $userId => $hash) {
            if ($this->isValidMd5((string)$hash)) {
                continue;
            }

            $this->userConfig->deleteUserConfig($userId, self::APP, self::KEY);
            $cleared++;
            $output->info('Cleared invalid password hash for user: ' . $userId);
        }

        if ($cleared > 0) {
            $output->warning(
                "Cleared {$cleared} invalid password hash(es). Those users need to set their "
                . 'KOReader sync password again in the web interface.'
            );
        } else {
            $output->info('No invalid password hashes found.');
        }

        // Leftover from an even earlier version that briefly stored the password
        // in the clear. deleteKey removes it for every user in one call.
        $this->userConfig->deleteKey(self::APP, self::KEY . '_plain');

        $output->info('Migration completed successfully.');
    }

    private function isValidMd5(string $hash): bool {
        return strlen($hash) === 32 && ctype_xdigit($hash);
    }
}
