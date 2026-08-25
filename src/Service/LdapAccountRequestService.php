<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LdapManageAccount;
use App\Entity\User;
use App\Enum\LdapAccountAction;
use App\Repository\LdapManageAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Posts a request into the ldap_manage_account queue - and, more to the point, refuses to.
 *
 * The rules live here and not on the screen, because the screen is not the only caller and because
 * a hidden button has never been an access control. Three of them:
 *
 *  - **One request at a time per account.** Two renames crossing, or a deactivation arriving in the
 *    middle of a rename, mean two scripts running against the same login in an order nobody chose.
 *  - **Nobody deactivates themselves.** Nothing technical stops it, which is exactly why it is
 *    stopped here: this platform has one administrator, and the screen that would undo it is the
 *    one they would have just locked themselves out of.
 *  - **A new login must be free, different and well-formed.** Free against both sources at once
 *    (LoginGenerator::loginTaken() reads User::$username *and* ldap_manage_user.login, so a login
 *    reserved by a creation that never went through counts as taken - which is also what keeps an
 *    old login reserved for ever after a rename). Well-formed because it is about to name a
 *    directory on a file server and sit inside a command line on a domain controller.
 *
 * What it does *not* do is change anything on this side. Deactivation switches at the click, in the
 * controller, before this is even called; a rename switches only once the directory has confirmed,
 * which is App\Service\LdapAccountVerifier's business. This class writes one row and nothing else.
 */
class LdapAccountRequestService
{
    /**
     * Lowercase, starts with a letter, then letters/digits/dot/dash/underscore, 2 to 64 long. It is
     * the shape LoginGenerator produces (`croux`, `yprigent01`) widened by the separators an
     * imported directory may already carry - not widened further: this string reaches
     * `samba-tool user rename` and `mv /srv/samba/userdata/<login>`.
     *
     * Public so the live availability check behind the field asks the same question the submission
     * will - a field that says "available" about something the POST then refuses is worse than a
     * field that says nothing.
     */
    public const string LOGIN_PATTERN = '/^[a-z][a-z0-9._-]{1,63}$/';

    public function __construct(
        private readonly LdapManageAccountRepository $requests,
        private readonly LoginGenerator $loginGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function disable(User $user, User $requestedBy): LdapManageAccount
    {
        if ($user === $requestedBy) {
            throw new LdapAccountRequestException('userCannotDeactivateSelfFlashMessage');
        }

        return $this->post($user, LdapAccountAction::Disable, null, $requestedBy);
    }

    public function enable(User $user, User $requestedBy): LdapManageAccount
    {
        return $this->post($user, LdapAccountAction::Enable, null, $requestedBy);
    }

    public function changeLogin(User $user, string $newLogin, User $requestedBy): LdapManageAccount
    {
        return $this->post($user, LdapAccountAction::LoginChange, $newLogin, $requestedBy);
    }

    /**
     * A failed attempt is retried by inserting a *new* row, never by resetting the old one back to
     * state 0 - the same reasoning as the class import: a queue row is the trace of one attempt,
     * and a counter that goes back to zero loses the only record that anything was tried.
     *
     * Every rule is re-run: between the failure and the retry the login may well have been taken by
     * somebody else.
     */
    public function retry(LdapManageAccount $failed, User $requestedBy): LdapManageAccount
    {
        $user = $failed->getUser();

        if (LdapAccountAction::Disable === $failed->getActionType() && $user === $requestedBy) {
            throw new LdapAccountRequestException('userCannotDeactivateSelfFlashMessage');
        }

        return $this->post($user, $failed->getActionType(), $failed->getNewLogin(), $requestedBy);
    }

    /** The login somebody typed, as the queue would store it - so a screen can ask about the same string. */
    public function normaliseLogin(string $login): string
    {
        return mb_strtolower(trim($login));
    }

    private function post(User $user, LdapAccountAction $action, ?string $newLogin, User $requestedBy): LdapManageAccount
    {
        if (null !== $this->requests->findPendingForUser($user)) {
            throw new LdapAccountRequestException('ldapAccountRequestAlreadyPendingMessage');
        }

        if ($action->requiresNewLogin()) {
            $newLogin = $this->checkNewLogin($user, $this->normaliseLogin($newLogin ?? ''));
        } else {
            $newLogin = null;
        }

        $request = new LdapManageAccount($user, $action, $newLogin);
        $request->setAddedBy($requestedBy->getUsername());

        $this->entityManager->persist($request);
        // Flushed here rather than left to the caller: the deactivation controller sets
        // User::$inactiveDate just before calling, so the two land in the same transaction - the
        // platform closing and the directory being told about it are one write, or the screen would
        // be able to show a closed account with nothing queued behind it.
        $this->entityManager->flush();

        return $request;
    }

    private function checkNewLogin(User $user, string $newLogin): string
    {
        if (1 !== preg_match(self::LOGIN_PATTERN, $newLogin)) {
            throw new LdapAccountRequestException('ldapAccountLoginInvalidMessage');
        }

        if ($newLogin === mb_strtolower($user->getUsername())) {
            throw new LdapAccountRequestException('ldapAccountLoginUnchangedMessage');
        }

        if ($this->loginGenerator->loginTaken($newLogin)) {
            throw new LdapAccountRequestException('ldapAccountLoginTakenMessage');
        }

        return $newLogin;
    }
}
