<?php

namespace App\Service;

use App\Repository\LdapManageUserRepository;
use App\Repository\UserRepository;

/**
 * Ports functions.php's generateUniqueLogin() (the ldap-manage Scripts project, consumed by
 * manage_user.php) to the moncampus side: first letter of firstname + lastname, lowercased,
 * ASCII-folded, appending 01/02/... on collision. Ported rather than shared, since the two
 * projects don't share a runtime - kept behaviourally identical on purpose (same account should
 * get the same login whichever side generates it).
 *
 * Checked here, before the account exists anywhere - unlike the original, which only checked
 * ldap_manage_user (safe there since login was only ever written after a successful LDAP
 * create). Now that App\Controller\DirectoryUserController::new() reserves the login immediately
 * (before LDAP creation even runs), a collision would otherwise leave a User row permanently
 * bound to a login someone else already holds - so this checks both User::$username (the new
 * source of truth going forward) and ldap_manage_user.login (every login ever generated,
 * including old rows from before this User-first change and rows still pending/failed).
 */
class LoginGenerator
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly LdapManageUserRepository $ldapManageUserRepository,
    ) {
    }

    public function generate(string $firstname, string $lastname): string
    {
        $base = $this->cleanNamePart($firstname, 1).$this->cleanNamePart($lastname);

        if (!$this->loginTaken($base)) {
            return $base;
        }

        for ($i = 1; $i <= 99; ++$i) {
            $candidate = $base.sprintf('%02d', $i);

            if (!$this->loginTaken($candidate)) {
                return $candidate;
            }
        }

        return $base.'.'.uniqid();
    }

    private function cleanNamePart(string $part, ?int $maxLength = null): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $part);
        $clean = preg_replace('/[^a-z]/', '', mb_strtolower(false !== $ascii ? $ascii : $part)) ?? '';

        return null !== $maxLength ? mb_substr($clean, 0, $maxLength) : $clean;
    }

    // Races between two concurrent creations are possible here (this check then a later insert
    // aren't atomic) - App\Controller\DirectoryUserController::new() retries generation on a
    // unique-constraint failure at flush time instead of trying to lock around this check, since
    // that's the simplest way to close the gap given how rarely two staff create an account in
    // the same instant.
    private function loginTaken(string $login): bool
    {
        return null !== $this->userRepository->findOneBy(['username' => $login])
            || $this->ldapManageUserRepository->loginExists($login);
    }
}
