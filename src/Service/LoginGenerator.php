<?php

declare(strict_types=1);

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

    /**
     * @param list<string> $reservedLogins logins the same run has already handed out but has not
     *                                     flushed yet - the two queries below can only see what the
     *                                     database holds, and a class routinely carries two students
     *                                     whose names fold to the same base ("Martin Dupont" and
     *                                     "Marie Dupont" are both mdupont). Empty for the one-account
     *                                     screen, which has nothing pending.
     */
    public function generate(string $firstname, string $lastname, array $reservedLogins = []): string
    {
        $base = $this->baseFor($firstname, $lastname);

        if (!$this->loginTaken($base, $reservedLogins)) {
            return $base;
        }

        for ($i = 1; $i <= 99; ++$i) {
            $candidate = $base.sprintf('%02d', $i);

            if (!$this->loginTaken($candidate, $reservedLogins)) {
                return $candidate;
            }
        }

        return $base.'.'.uniqid();
    }

    /**
     * The login a name gives before any collision is dealt with - `Camille Roux` → `croux`, always,
     * whoever already holds it.
     *
     * generate() above cannot answer this: it checks the two sources and walks to `croux01` the
     * moment `croux` is taken, and the account whose own login is `croux` makes it taken. What the
     * rename modal offers is "what would this name give", which is often the current login itself -
     * and saying so plainly is more use than suggesting `croux01`, which nobody wants.
     */
    public function baseFor(string $firstname, string $lastname): string
    {
        return $this->cleanNamePart($firstname, 1).$this->cleanNamePart($lastname);
    }

    private function cleanNamePart(string $part, ?int $maxLength = null): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $part);
        $clean = preg_replace('/[^a-z]/', '', mb_strtolower(false !== $ascii ? $ascii : $part)) ?? '';

        return null !== $maxLength ? mb_substr($clean, 0, $maxLength) : $clean;
    }

    // Races between two concurrent creations remain possible here (this check and the later insert
    // aren't atomic): two staff members creating an account in the same instant, or two imports of
    // the same class, would both be handed the same base and the second flush would fail on
    // User::$username's unique constraint. Deliberately not locked around: the collision window is
    // a few milliseconds twice a year, the failure is loud (the whole write is refused, nothing is
    // half-written) and retrying costs one click.
    //
    // $reservedLogins closes the other, far likelier collision - the one inside a single run, which
    // no query can see.
    //
    // Public since the "Changer le login" screen asks the same question about a login somebody
    // typed (App\Service\LdapAccountRequestService, and the live availability check behind the
    // field): a login reserved by a creation that never went through is taken every bit as much as
    // one somebody carries, and answering that twice is how the two answers would come to differ.
    // It is also why an old login stays reserved for ever after a rename - ldap_manage_user keeps
    // its row.
    /** @param list<string> $reservedLogins */
    public function loginTaken(string $login, array $reservedLogins = []): bool
    {
        return \in_array($login, $reservedLogins, true)
            || null !== $this->userRepository->findOneBy(['username' => $login])
            || $this->ldapManageUserRepository->loginExists($login);
    }
}
