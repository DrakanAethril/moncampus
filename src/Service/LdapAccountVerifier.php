<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LdapManageAccount;
use App\Enum\LdapAccountAction;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ExceptionInterface as LdapException;
use Symfony\Component\Ldap\LdapInterface;

/**
 * The second of the two proofs.
 *
 * The first is the consumer script's exit code, and it is not enough: this application does not run
 * the script - that is the whole reason there is a queue - so all it knows is what the script wrote
 * into the row. A script that exits 0 without having done anything would therefore make the screen
 * say « réussi », and §8 of the design shows that is not a school case: change_login.sh tested only
 * its second command for a good year.
 *
 * There is a second source and it was already wired: this application has its own read-only LDAP
 * connection, the service account App\Service\LdapUserSyncer binds as. So it goes and looks.
 *
 * **It never concludes to a contradiction it cannot prove.** Three things stop it short - the
 * directory being unreachable, the status attribute not being configured, the entry not carrying
 * it - and all three leave `verified_at` NULL with a note saying which. In development that is the
 * ordinary case: the OpenLDAP container has no userAccountControl at all, so every local
 * deactivation sits at « réussi, non vérifié », and a developer who read « the script is lying »
 * there would stop reading the warning on the day it means something.
 *
 * A rename is the exception: `(uid=new)` existing and `(uid=old)` no longer answering is conclusive
 * on both directories, dev included. It is also the only action whose consequence waits on this
 * verdict - see App\Service\LdapAccountApplier.
 */
class LdapAccountVerifier
{
    /** 0x0002, ACCOUNTDISABLE - the bit `samba-tool user disable` sets. */
    private const int ACCOUNT_DISABLE_BIT = 2;

    public const string NOTE_DIRECTORY_UNREACHABLE = 'ldapAccountVerificationUnreachableNote';
    public const string NOTE_ATTRIBUTE_NOT_CONFIGURED = 'ldapAccountVerificationAttributeNotConfiguredNote';
    public const string NOTE_ATTRIBUTE_MISSING = 'ldapAccountVerificationAttributeMissingNote';
    public const string NOTE_ENTRY_MISSING = 'ldapAccountVerificationEntryMissingNote';
    public const string NOTE_STILL_ENABLED = 'ldapAccountVerificationStillEnabledNote';
    public const string NOTE_STILL_DISABLED = 'ldapAccountVerificationStillDisabledNote';
    public const string NOTE_OLD_LOGIN_STILL_THERE = 'ldapAccountVerificationOldLoginStillThereNote';
    public const string NOTE_NEW_LOGIN_MISSING = 'ldapAccountVerificationNewLoginMissingNote';

    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly string $ldapBaseDn,
        private readonly string $ldapUserBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
        private readonly string $ldapUsernameAttribute,
        private readonly string $ldapAccountStatusAttribute,
    ) {
    }

    /**
     * Reads the directory back and stamps the row - `verified_at` when it agrees, a note saying why
     * not otherwise. Never writes `state`: what the script said, the script said.
     *
     * Does nothing at all unless the script claimed success: a failed row has nothing to confirm,
     * and a row still running has not made its claim yet.
     */
    public function verify(LdapManageAccount $request): void
    {
        if (2 !== $request->getState() || null !== $request->getVerificationDate()) {
            return;
        }

        try {
            $note = match ($request->getActionType()) {
                LdapAccountAction::LoginChange => $this->checkRename($request),
                LdapAccountAction::Disable => $this->checkDisabledBit($request->getLogin(), true),
                LdapAccountAction::Enable => $this->checkDisabledBit($request->getLogin(), false),
            };
        } catch (LdapException) {
            // Neither confirmed nor contradicted. The cron command comes back in a minute, and
            // nothing was invented in the meantime.
            $request->setVerificationNote(self::NOTE_DIRECTORY_UNREACHABLE);

            return;
        }

        if (null === $note) {
            $request->setVerificationDate(new \DateTimeImmutable());
            $request->setVerificationNote(null);

            return;
        }

        $request->setVerificationNote($note);
    }

    /** @return string|null the note explaining the refusal, or null when the directory agrees */
    private function checkRename(LdapManageAccount $request): ?string
    {
        $newLogin = $request->getNewLogin();

        if (null === $newLogin) {
            // A login_change row with no target login is a row nobody could have written through
            // LdapAccountRequestService; there is nothing to look for.
            return self::NOTE_NEW_LOGIN_MISSING;
        }

        if (null === $this->findEntry($newLogin)) {
            return self::NOTE_NEW_LOGIN_MISSING;
        }

        // Conclusive on both directories, which is why a rename is the one action whose consequence
        // waits on this verdict.
        if (null !== $this->findEntry($request->getLogin())) {
            return self::NOTE_OLD_LOGIN_STILL_THERE;
        }

        return null;
    }

    private function checkDisabledBit(string $login, bool $expectedDisabled): ?string
    {
        if ('' === $this->ldapAccountStatusAttribute) {
            // Development, and any directory that has no such notion. Not a contradiction.
            return self::NOTE_ATTRIBUTE_NOT_CONFIGURED;
        }

        $entry = $this->findEntry($login);

        if (null === $entry) {
            return self::NOTE_ENTRY_MISSING;
        }

        $raw = ($entry->getAttribute($this->ldapAccountStatusAttribute) ?? [])[0] ?? null;

        if (!is_numeric($raw)) {
            return self::NOTE_ATTRIBUTE_MISSING;
        }

        $disabled = 0 !== ((int) $raw & self::ACCOUNT_DISABLE_BIT);

        if ($disabled === $expectedDisabled) {
            return null;
        }

        return $expectedDisabled ? self::NOTE_STILL_ENABLED : self::NOTE_STILL_DISABLED;
    }

    private function findEntry(string $login): ?Entry
    {
        $this->ldap->bind($this->ldapSearchDn, $this->ldapSearchPassword);

        $escaped = $this->ldap->escape($login, '', LdapInterface::ESCAPE_FILTER);
        $results = $this->ldap
            ->query($this->resolveUserBaseDn(), \sprintf('(%s=%s)', $this->ldapUsernameAttribute, $escaped))
            ->execute();

        return $results[0] ?? null;
    }

    // Same fallback as LdapUserSyncer: narrowing matters on a Samba 4 AD DC, where computer and
    // service accounts are objectClass=user entries too.
    private function resolveUserBaseDn(): string
    {
        return '' !== $this->ldapUserBaseDn ? $this->ldapUserBaseDn : $this->ldapBaseDn;
    }
}
