<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service;

use OCP\Config\IUserConfig;

/**
 * Storage and verification of the KOReader sync password.
 *
 * The protocol fixes one end of this: KOReader sends MD5(password) in the
 * `x-auth-key` header and nothing else, so the MD5 is what arrives over the wire
 * and that cannot be negotiated away.
 *
 * What the app *did* control, and got wrong, was storage: it kept that same bare
 * unsalted MD5 in config, which made the stored value the credential. Anything
 * that could read config -- a database backup, a read primitive in another
 * installed app -- yielded a directly replayable secret and, because MD5 of a
 * user-chosen password is a rainbow-table lookup, usually the plaintext too. And
 * users reuse passwords.
 *
 * So the received MD5 is treated as the password and stored under
 * password_hash(). Instances written before this change hold a bare MD5; those
 * are still accepted and transparently upgraded on the next successful sync, so
 * nobody has to re-enter anything.
 */
class SyncPasswordService {

    private const CONFIG_APP = 'koreader_companion';
    private const CONFIG_KEY = 'koreader_sync_password';

    /**
     * A valid bcrypt digest that nothing verifies against.
     *
     * Used to spend roughly the same time on "no such user" and "no password
     * set" as on a real check, so response timing does not enumerate accounts.
     */
    private const DUMMY_HASH = '$2y$12$HXTq/Mhe2pQ7hnDtahyfG.A9gGm1.HPKMeYpmcM6anH9EaukGqrlO';

    /**
     * Shortest sync password accepted. Enforced here rather than in the browser,
     * where the old four-character rule lived and was trivially bypassed by
     * calling the endpoint directly.
     */
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private IUserConfig $config,
    ) {
    }

    public function hasPassword(string $userId): bool {
        return $this->storedValue($userId) !== '';
    }

    /**
     * @return string|null An error message, or null when the password was stored.
     */
    public function setPassword(string $userId, string $password): ?string {
        if ($password === '') {
            return 'Password is required';
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters';
        }

        // md5() first because that is what the client will send; password_hash()
        // is what makes it safe to keep.
        $this->store($userId, md5($password));

        return null;
    }

    /**
     * Verify the `x-auth-key` value a client sent.
     *
     * @param string $authKey MD5 hex as received from KOReader.
     */
    public function verify(string $userId, string $authKey): bool {
        $stored = $this->storedValue($userId);

        if ($stored === '') {
            // Same work as a real verification, so an unset password and a wrong
            // one are not distinguishable by timing.
            password_verify($authKey, self::DUMMY_HASH);
            return false;
        }

        if ($this->isLegacyBareMd5($stored)) {
            if (!hash_equals($stored, $authKey)) {
                return false;
            }
            $this->store($userId, $authKey);
            return true;
        }

        return password_verify($authKey, $stored);
    }

    /**
     * Pre-8.9 rows hold the MD5 itself: 32 hex characters. A password_hash()
     * digest always starts with '$' and is longer, so the two never collide.
     */
    private function isLegacyBareMd5(string $stored): bool {
        return strlen($stored) === 32 && ctype_xdigit($stored);
    }

    private function store(string $userId, string $md5Hex): void {
        $this->config->setValueString(
            $userId,
            self::CONFIG_APP,
            self::CONFIG_KEY,
            password_hash($md5Hex, PASSWORD_DEFAULT),
            flags: IUserConfig::FLAG_SENSITIVE,
        );
    }

    private function storedValue(string $userId): string {
        return $this->config->getValueString($userId, self::CONFIG_APP, self::CONFIG_KEY, '');
    }
}
