<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * One-time conversion of app-wide settings into per-user settings.
 *
 * Everything here goes through IAppConfig / IUserConfig / IUserManager instead of
 * querying oc_appconfig, oc_preferences and oc_users directly. Those raw queries
 * are unsupported for apps and NC 31+ made them actively unsafe by adding typed
 * and lazy columns to the config tables.
 *
 * Note this step used to also run DROP TABLE IF EXISTS `koreader_file_tracking`
 * with MySQL backtick quoting, which is a syntax error on PostgreSQL and was
 * swallowed by a catch. Version0006 drops that table declaratively instead.
 */
class Version0004Date20250916000000 extends SimpleMigrationStep {

    private const APP = 'koreader_companion';

    /** Settings that move from app-wide to per-user. */
    private const MIGRATED_KEYS = ['folder', 'auto_rename'];

    /** Settings that are simply gone; the features were removed. */
    private const DROPPED_KEYS = ['restrict_uploads', 'auto_cleanup'];

    public function __construct(
        private IAppConfig $appConfig,
        private IUserConfig $userConfig,
        private IUserManager $userManager,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $output->info('Converting app-wide settings to per-user settings...');

        $appSettings = [];
        foreach (self::MIGRATED_KEYS as $key) {
            if ($this->appConfig->hasKey(self::APP, $key)) {
                $appSettings[$key] = $this->appConfig->getValueString(self::APP, $key);
            }
        }
        $output->info(sprintf('Found %d app-wide settings to migrate', count($appSettings)));

        if ($appSettings !== []) {
            // callForSeenUsers rather than SELECT uid FROM users: it works across
            // every user backend, not just the local database one.
            $userCount = 0;
            $this->userManager->callForSeenUsers(function ($user) use ($appSettings, &$userCount): void {
                $userId = $user->getUID();
                foreach ($appSettings as $key => $value) {
                    // Never clobber a value the user already chose.
                    if (!$this->userConfig->hasKey($userId, self::APP, $key)) {
                        $this->userConfig->setValueString($userId, self::APP, $key, $value);
                    }
                }
                $userCount++;
            });
            $output->info(sprintf('Migrated settings for %d users', $userCount));
        }

        foreach (array_merge(self::MIGRATED_KEYS, self::DROPPED_KEYS) as $key) {
            $this->appConfig->deleteKey(self::APP, $key);
        }
        $output->info('Removed old app-wide settings from appconfig');

        foreach (self::DROPPED_KEYS as $key) {
            $this->userConfig->deleteKey(self::APP, $key);
        }
        $output->info('Cleaned up per-user settings for removed features');

        $output->info('Migration completed successfully.');
        $output->info('Settings are now per-user with defaults: ' . json_encode([
            'folder' => $appSettings['folder'] ?? 'eBooks',
            'auto_rename' => $appSettings['auto_rename'] ?? 'no',
        ]));
    }
}
