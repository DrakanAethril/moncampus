<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LdapManageUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates one account: the App\Entity\User row, its roles, its verified contact address, its School
 * mail addresses and the App\Entity\LdapManageUser line the directory script will pick up.
 *
 * Extracted from App\Controller\DirectoryUserController::new(), which now calls it, so that the
 * class import cannot create accounts that are subtly different from the ones staff create by hand
 * - the two paths are the same code or they are a bug waiting to happen.
 *
 * The User row is created immediately, before LDAP has provisioned anything, so the account is
 * usable straight away through App\Security\MagicLinkAuthenticator, and so relations (a class, a
 * mailbox, a message) can attach to it. Nothing is flushed here: the caller owns the transaction,
 * which matters for the import, where thirty accounts go in together or not at all.
 */
class StudentAccountFactory
{
    // create_user.sh's own "--mail=$login@$DOMAIN" in the ldap-manage Scripts project. Pre-filled
    // to what the account's first real LDAP login will set it to anyway (App\Security\
    // LdapUserMapper) rather than left blank in the meantime.
    private const string DIRECTORY_DOMAIN = 'beaupeyrat.lan';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoginGenerator $loginGenerator,
        private readonly LdapManageUserRoleResolver $roleResolver,
        private readonly ContactEmailVerifier $contactEmailVerifier,
        private readonly StudentMailProvisioner $mailProvisioner,
        private readonly UserLoginHistory $loginHistory,
    ) {
    }

    /**
     * @param list<string> $reservedLogins logins this same run has already handed out but not yet
     *                                     flushed - the generator can only see what the database
     *                                     holds, and "Martin Dupont" and "Marie Dupont" of the same
     *                                     class both fold to mdupont
     */
    public function create(NewAccountRequest $request, array $reservedLogins = []): CreatedAccount
    {
        $login = $this->loginGenerator->generate($request->firstname, $request->lastname, $reservedLogins);
        $contactEmail = self::blankToNull($request->contactEmail);

        // Built even when it is not queued: it is also what the role resolver reads, and the same
        // 'ROLE_'.strtoupper($cn) convention has to hold whether the account reaches LDAP or not.
        $directoryRequest = new LdapManageUser($request->firstname, $request->lastname, $request->userType, 'account_create');
        $directoryRequest->setUserGroups(implode('|', $request->groups));
        $directoryRequest->setAddedBy($request->addedBy);
        $directoryRequest->setLogin($login);

        $user = new User($login);
        $user->setEmail($login.'@'.self::DIRECTORY_DOMAIN);
        $user->setFirstname($request->firstname);
        $user->setLastname($request->lastname);
        $user->setRoles($this->roleResolver->resolve($directoryRequest));
        $user->setContactEmail($contactEmail);
        $user->setPhoneNumber(self::blankToNull($request->phoneNumber));
        $user->setMustChangePassword($request->mustChangePassword);
        $user->setTestUser($request->testUser);

        // Staff creating the account is trusted outright - no confirmation mail, ever, from either
        // path. See ContactEmailVerifier's docblock.
        if (null !== $contactEmail) {
            $this->contactEmailVerifier->markVerifiedByStaff($user);
        }

        $this->entityManager->persist($user);

        // The account's first login goes into the ledger straight away: user_login is meant to hold
        // *every* login an account ever answered to, and a row that only appears at the first
        // rename would make the history start in the middle.
        $this->loginHistory->record($user, $login);

        if (!$request->directoryAccount) {
            return new CreatedAccount($user, null);
        }

        // manage_user.php's getUserLine() reads the login column directly instead of generating it,
        // so leaving it null would hand create_user.sh an empty login.
        $directoryRequest->setUser($user);
        $this->entityManager->persist($directoryRequest);

        // A student leaves with their School mail addresses already composed, the same ones
        // App\Command\BackfillStudentMailAliasesCommand would have given them later - reception
        // being catch-all, an address only exists once the row does, so waiting for the backfill
        // meant mail to a brand-new student falling into the "to be linked" queue in the meantime.
        $schoolMailFailed = false;
        if ('student' === $request->userType) {
            try {
                $this->mailProvisioner->provisionFor($user);
            } catch (\RuntimeException) {
                $schoolMailFailed = true;
            }
        }

        return new CreatedAccount($user, $directoryRequest, $schoolMailFailed);
    }

    // A form hands back an empty string where a nullable column wants null; storing "" instead
    // would make "no address" and "an address nobody typed" two different things everywhere after.
    private static function blankToNull(?string $value): ?string
    {
        $value = trim($value ?? '');

        return '' !== $value ? $value : null;
    }
}
