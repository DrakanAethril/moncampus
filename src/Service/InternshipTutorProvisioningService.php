<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipTutorLink;
use App\Entity\LdapManageUser;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the platform account behind an alternance's entreprise tutor, without ever blocking the
 * staff member's request on the LDAP account actually existing (see App\Entity\LdapManageUser for
 * the async queue this hands off to - inserting a row here is a plain, instant DB write, never a
 * wait on the separate consumer script that actually provisions the account).
 *
 * The User row is created up front, exactly the way App\Controller\DirectoryUserController::new()
 * does for a staff-created account: login generated on this side (App\Service\LoginGenerator),
 * ROLE_TUTOR derived from the queue row's own userType, and - the point of the whole exercise -
 * the address staff typed stored as User::$contactEmail, marked verified outright. Staff naming a
 * tutor is trusted the same way staff typing a contact e-mail on the user-creation form is (see
 * ContactEmailVerifier::markVerifiedByStaff()), so the tutor is never asked to confirm an inbox
 * before the platform will write to them.
 *
 * That contact e-mail is then the ONLY address anything mails a tutor at - no free-text fallback
 * is left anywhere. See App\Entity\InternshipTutorLink's own docblock for what this replaced.
 */
class InternshipTutorProvisioningService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly LoginGenerator $loginGenerator,
        private readonly LdapManageUserRoleResolver $roleResolver,
        private readonly ContactEmailVerifier $contactEmailVerifier,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserLoginHistory $loginHistory,
    ) {
    }

    /**
     * Creates the tutor's account plus the LDAP request that will materialise it, and hangs both
     * off $tutorLink.
     *
     * Callers MUST have rejected an already-taken contact e-mail first (see isEmailAvailable()) -
     * User::$contactEmail is unique, so reaching this with a duplicate would blow up at flush time
     * with a constraint violation instead of a field-level form error.
     */
    public function provision(
        InternshipTutorLink $tutorLink,
        string $firstname,
        string $lastname,
        string $contactEmail,
        string $phone,
        bool $testUser,
        User $requestedBy,
    ): User {
        $ldapUser = new LdapManageUser($firstname, $lastname, 'tutor', 'account_create');
        $ldapUser->setAddedBy($requestedBy->getUsername());

        $login = $this->loginGenerator->generate($firstname, $lastname);

        // Same pre-fill as DirectoryUserController::new(): $email/$roles are what this account's
        // first real LDAP login will set them to anyway (App\Security\LdapUserMapper), so the
        // account reads correctly in the meantime instead of showing blanks. $contactEmail is the
        // opposite - app-owned, never touched by the LDAP sync, and the whole reason this method
        // creates a User at all rather than only queueing a request.
        $user = new User($login);
        $user->setEmail($login.'@beaupeyrat.lan');
        $user->setFirstname($firstname);
        $user->setLastname($lastname);
        $user->setRoles($this->roleResolver->resolve($ldapUser));
        $user->setContactEmail($contactEmail);
        $user->setPhoneNumber('' !== $phone ? $phone : null);
        // A fake alternance must not spawn a real-looking account: the box ticked on the creation
        // form propagates here (see InternshipTutorLink::$testAlternance).
        $user->setTestUser($testUser);
        $this->contactEmailVerifier->markVerifiedByStaff($user);

        // The ledger starts at the account's first login, not at its first rename.
        $this->loginHistory->record($user, $login);

        $ldapUser->setLogin($login);
        $ldapUser->setUser($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($ldapUser);

        $tutorLink->setTutor($user);
        $tutorLink->setLdapManageUser($ldapUser);

        return $user;
    }

    // Whether a brand new tutor may be created with this contact e-mail. False means some account
    // already holds it - staff are told to pick that tutor through the "tuteur existant" mode
    // rather than silently attaching this alternance to someone else's account, since the address
    // could just as well belong to a teacher or a student.
    public function isEmailAvailable(string $contactEmail): bool
    {
        return null === $this->userRepository->findOneBy(['contactEmail' => $contactEmail]);
    }
}
