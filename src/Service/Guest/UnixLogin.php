<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * What a Unix login may be.
 *
 * The rules are deliberately narrow - lowercase ASCII letters, digits, and a hyphen, starting with
 * a letter, 32 characters - because that is what useradd accepts everywhere without argument.
 *
 * This class used to *build* logins too, out of a student's name. It no longer does: a login is now
 * the platform username, read as it stands (App\Service\VmBatch\BatchMemberResolver), so the only
 * question left is whether a given one is usable - which callers must ask, because
 * App\Service\Guest\GuestAccountSyncer skips an account it cannot create rather than raising.
 */
class UnixLogin
{
    private const int MAX_LENGTH = 32;

    /** Reserved on any Linux; a student called Sarah Yourdon must not become `sys`. */
    private const array RESERVED = ['root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail', 'news', 'proxy', 'www-data', 'backup', 'nobody', 'systemd', 'sshd', 'admin'];

    /** Whether a login somebody typed is one useradd would accept. */
    public function isValid(string $login): bool
    {
        if ('' === $login || \strlen($login) > self::MAX_LENGTH || \in_array($login, self::RESERVED, true)) {
            return false;
        }

        return 1 === preg_match('/^[a-z][a-z0-9-]*$/', $login);
    }
}
