<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EmailAlias;
use App\Entity\LdapManageUser;
use App\Entity\User;
use App\Enum\EmailAliasOrigin;
use App\Form\LdapManageUserType;
use App\Form\UserProfileType;
use App\Repository\GroupRepository;
use App\Repository\LdapManageUserRepository;
use App\Repository\StudentImportBatchRepository;
use App\Repository\UserRepository;
use App\Security\Voter\FileLibraryVoter;
use App\Service\ByteSize;
use App\Service\ContactEmailVerifier;
use App\Service\DataTableParams;
use App\Service\FileLibraryQuota;
use App\Service\FormValue;
use App\Service\LdapManageUserRoleResolver;
use App\Service\NewAccountRequest;
use App\Service\QueueStateFormatter;
use App\Service\StudentAccountFactory;
use App\Service\StudentMailAliasValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class DirectoryUserController extends AbstractController
{
    #[Route(path: '/directory/users', name: 'app_directory_users')]
    public function index(StudentImportBatchRepository $importBatches): Response
    {
        return $this->render('directory/users.html.twig', [
            // Only administrators reach the class import, and the template hides the menu for
            // anyone else - the query is cheap enough not to be worth a second branch here.
            'recentImports' => $this->isGranted('ROLE_ADMIN') ? $importBatches->findRecent() : [],
        ]);
    }

    // Creates the User row immediately (not just the ldap_manage_user request) so the account is
    // usable via App\Security\MagicLinkAuthenticator - the contact email set here is verified
    // outright (ContactEmailVerifier::markVerifiedByStaff(), no confirmation mail/click-through)
    // - before LDAP has actually provisioned it, which can take anywhere from minutes to days
    // depending on when the consumer script next runs. See LoginGenerator's docblock for why
    // login uniqueness must now be checked before this point, not after.
    #[Route(path: '/directory/users/new', name: 'app_directory_users_new')]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        GroupRepository $groupRepository,
        UserRepository $userRepository,
        StudentAccountFactory $accountFactory,
        TranslatorInterface $translator,
    ): Response {
        // Only account creation is supported from this form; password-change requests go through
        // the separate Directory > Mots de passe screen (App\Controller\DirectoryPasswordController),
        // backed by App\Entity\LdapManagePassword instead of this queue.
        $ldapUser = new LdapManageUser('', '', '', 'account_create');
        $form = $this->createForm(LdapManageUserType::class, $ldapUser);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $contactEmail = $form->get('contactEmail')->getData();

            // App\Entity\User carries a #[UniqueEntity] constraint on contactEmail, but it's
            // never actually validated here - $form's data_class is LdapManageUser, not the User
            // being built below, so that constraint never runs against it. Checked explicitly
            // instead (design/design_handoff_utilisateurs/README.md rule 8).
            if (null !== $userRepository->findOneBy(['contactEmail' => $contactEmail])) {
                $form->get('contactEmail')->addError(new FormError($translator->trans('contactEmailAlreadyUsedMessage')));
            } else {
                /** @var User $currentUser */
                $currentUser = $this->getUser();

                // Every field of the account is set by App\Service\StudentAccountFactory, which the
                // class import calls too - the two paths create the same account or one of them is
                // a bug nobody sees. The queue row built by the form is only read here, never
                // persisted: the factory builds its own.
                $account = $accountFactory->create(new NewAccountRequest(
                    firstname: $ldapUser->getFirstname(),
                    lastname: $ldapUser->getLastname(),
                    userType: $ldapUser->getUserType(),
                    addedBy: $currentUser->getUsername(),
                    groups: array_values(array_filter(explode('|', $ldapUser->getUserGroups()))),
                    contactEmail: FormValue::trimmed($form, 'contactEmail'),
                    phoneNumber: FormValue::trimmed($form, 'phoneNumber'),
                    mustChangePassword: FormValue::bool($form, 'mustChangePassword'),
                    testUser: FormValue::bool($form, 'testUser'),
                ));

                if ($account->schoolMailFailed) {
                    // A civil status that transliterates to nothing (or a hundredth namesake) is no
                    // reason to refuse the account: it is created without an address, and staff are
                    // told to give it one by hand from the edit screen's "Adresses Courrier école"
                    // section.
                    $this->addFlash('warning', 'userMailAliasNotProvisionedFlashMessage');
                }

                $entityManager->flush();

                $this->addFlash('success', 'userCreatedFlashMessage');

                return $this->redirectToRoute('app_directory_users');
            }
        }

        return $this->render('directory/user_form.html.twig', [
            'form' => $form,
            // Same excluded-names list LdapManageUserType::availableSecondaryGroups() passes for
            // the form's own choices - kept in one place (LdapManageUserType::excludedGroupNames())
            // so the chips rendered here can never include a group the form itself would reject.
            'groupBuckets' => $groupRepository->findAllActiveGroupedByType(LdapManageUserType::excludedGroupNames()),
        ]);
    }

    // Moved from the now-removed App\Controller\UserManagementController (/users/{id}/edit) when
    // that standalone "Gestion > Utilisateurs" screen was folded into this one - edits only
    // User's local-only fields (contact email, phone, manually assigned groups, and now the
    // forced-password-renewal flag); username/email/firstname/lastname/roles stay LDAP-owned and
    // are shown read-only rather than not exposed at all, on the same template new() uses
    // (directory/user_form.html.twig) so staff see the identical layout in both modes.
    #[Route(path: '/directory/users/{id}/edit', name: 'app_directory_users_edit')]
    public function edit(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $repository,
        ContactEmailVerifier $contactEmailVerifier,
        GroupRepository $groupRepository,
        LdapManageUserRoleResolver $roleResolver,
        LdapManageUserRepository $ldapManageUserRepository,
        QueueStateFormatter $stateFormatter,
        StudentMailAliasValidator $aliasValidator,
        TranslatorInterface $translator,
        FileLibraryQuota $libraryQuota,
        FileLibraryVoter $libraryVoter,
        #[Autowire('%env(MAIL_STUDENT_DOMAIN)%')]
        string $studentMailDomain,
        int $id,
    ): Response {
        $user = $repository->find($id) ?? throw $this->createNotFoundException();

        // Admin profiles are edited through LDAP directly, not this screen - the Modifier action
        // is already hidden for them in the list (see App\Controller\DirectoryUserController::data()
        // and assets/controllers/datatable_controller.js), this is the server-side enforcement of
        // the same rule in case someone still reaches this URL directly.
        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw $this->createAccessDeniedException();
        }

        $previousEmail = $user->getContactEmail();

        $ldapRoles = $user->getLdapRoles();
        $groupBuckets = $groupRepository->findAllActiveGroupedByType(LdapManageUserType::excludedGroupNames());
        $adGroupNames = [];
        foreach ($groupBuckets as $bucket) {
            foreach ($bucket['groups'] as $group) {
                if (\in_array($group->getRole(), $ldapRoles, true)) {
                    $adGroupNames[] = $group->getName();
                }
            }
        }
        // Only the buckets/groups actually carried by this user's annuaire membership - the
        // template renders these as the locked "Groupes issus de l'annuaire" chips.
        $adGroupBuckets = [];
        foreach ($groupBuckets as $bucket) {
            $bucketAdGroups = array_values(array_filter(
                $bucket['groups'],
                static fn ($group) => \in_array($group->getName(), $adGroupNames, true),
            ));
            if ([] !== $bucketAdGroups) {
                $adGroupBuckets[] = ['label' => $bucket['label'], 'groups' => $bucketAdGroups];
            }
        }

        // School mail addresses only exist for students (App\Service\StudentMailProvisioner) - the
        // type is what decides whether the section is there at all, here as in the form itself.
        $resolvedType = $roleResolver->resolveTypeFromRoles($ldapRoles);
        $isStudent = 'student' === $resolvedType;

        // Taken before handleRequest(): the reference state of the addresses that aren't
        // administrable, which a hand-crafted POST could make disappear from the collection - the
        // screen itself never offers to remove them. See applyEmailAliases().
        $lockedAliases = $isStudent
            ? $user->getEmailAliases()->filter(static fn ($alias) => !$alias->getOrigin()->isManageable())->toArray()
            : [];

        // The quota is the administrator's alone, and only means anything for an account that has a
        // library at all - a student has none. Staff also reach this screen, and see the usage bar
        // without the field (see the template).
        $hasLibrary = $libraryVoter->hasLibrary($user);
        $quotaEditable = $hasLibrary && $this->isGranted('ROLE_ADMIN');

        $form = $this->createForm(UserProfileType::class, $user, [
            'adGroupNames' => $adGroupNames,
            'emailAliasesEditable' => $isStudent,
            'fileLibraryQuotaEditable' => $quotaEditable,
        ]);
        // The field shows the override and nothing else: an empty box means "the platform default",
        // which is what the help text under it says.
        if ($quotaEditable) {
            $form->get('fileLibraryQuota')->setData(
                null === $user->getFileLibraryQuotaBytes() ? '' : ByteSize::format($user->getFileLibraryQuotaBytes()),
            );
        }
        $form->handleRequest($request);

        // The addresses are checked alongside the form's own validation, and a refusal blocks the
        // whole screen from saving: an address turned down as a duplicate is not a detail to pass
        // over in silence while everything else goes through.
        $aliasesAccepted = !$isStudent || !$form->isSubmitted() || $this->applyEmailAliases($form, $user, $aliasValidator, $translator, $lockedAliases);
        // Same rule for the quota: a size nobody can read stops the submission on the field rather
        // than being dropped in silence, which on a nullable column would read as "back to the
        // platform default".
        $quotaAccepted = !$quotaEditable || !$form->isSubmitted() || $this->applyFileLibraryQuota($form, $user, $translator);

        if ($form->isSubmitted() && $form->isValid() && $aliasesAccepted && $quotaAccepted) {
            $newEmail = $user->getContactEmail();

            // Unlike ProfileController::updateContactEmail() (self-service), staff setting this
            // on someone else's behalf is trusted outright - see ContactEmailVerifier's docblock.
            if ($newEmail !== $previousEmail) {
                if (null === $newEmail) {
                    $user->setContactEmailVerifiedAt(null)->setContactEmailToken(null)->setContactEmailTokenRequestedAt(null);
                } else {
                    $contactEmailVerifier->markVerifiedByStaff($user);
                }
            }

            $entityManager->flush();

            $this->addFlash('success', 'userProfileUpdatedFlashMessage');

            return $this->redirectToRoute('app_directory_users');
        }

        // Where this account's own annuaire creation request stands. Null for everyone who never
        // went through this app's queue at all (accounts created straight in the annuaire, which
        // the LDAP authenticator provisions on first login) - the template shows a dash for those
        // rather than hiding the line, same convention as $resolvedType above.
        $ldapManageUser = $ldapManageUserRepository->findMostRecentForUser($user);
        $ldapAddLog = $ldapManageUser?->getLog();

        return $this->render('directory/user_form.html.twig', [
            'form' => $form,
            'editedUser' => $user,
            'resolvedType' => $resolvedType,
            'showEmailAliases' => $isStudent,
            'studentMailDomain' => $studentMailDomain,
            'adGroupBuckets' => $adGroupBuckets,
            'manualGroupBuckets' => $groupRepository->findManuallyAssignableGroupedByType($adGroupNames),
            'ldapAddStatus' => null === $ldapManageUser ? null : [
                'label' => $stateFormatter->label($ldapManageUser->getState()),
                'class' => $stateFormatter->cssClass($ldapManageUser->getState()),
                // Only surfaced on a failed row: the consumer script writes to `log` on the way
                // through as well, so a pending/succeeded row's log is progress noise, not an
                // error to put in front of staff.
                'error' => 3 === $ldapManageUser->getState() && null !== $ldapAddLog && '' !== trim($ldapAddLog) ? trim($ldapAddLog) : null,
            ],
            // The file-library section, and only for an account that has a library at all - a
            // student has none, and an empty section would invite the question of why
            // (design/validated/file-library.md, "The admin quota field"). The number itself is a
            // field of the form above; what is left here is the usage the screen displays.
            'fileLibraryQuota' => $hasLibrary ? [
                'usedLabel' => ByteSize::format($libraryQuota->usedBytes($user)),
                'limitLabel' => ByteSize::format($libraryQuota->limitFor($user)),
                'percent' => $libraryQuota->usedPercent($user),
                'level' => $libraryQuota->level($user),
                'defaultLabel' => ByteSize::format($libraryQuota->defaultBytes()),
            ] : null,
        ]);
    }

    /**
     * Takes over what edit()'s "Bibliothèque de fichiers" section submitted: the size an admin
     * typed, read into the bigint the column holds.
     *
     * Empty clears the override back to NULL - which is not zero, it is "whatever the platform
     * currently says". That is the whole reason the column is nullable, and it is why an
     * unreadable size cannot be treated as empty: lowering somebody's quota to the default by
     * typo is not something to do silently.
     *
     * Lowering it below what the teacher already stores deletes nothing: the bar reads 118 %, in
     * red, and uploads are refused until they free space. Any other behaviour would mean deleting
     * somebody's files as a side effect of an administrative edit.
     *
     * @return bool false when the size could not be read, the error being then hung on the field
     */
    private function applyFileLibraryQuota(FormInterface $form, User $user, TranslatorInterface $translator): bool
    {
        $typed = FormValue::trimmed($form, 'fileLibraryQuota');

        if ('' === $typed) {
            $user->setFileLibraryQuotaBytes(null);

            return true;
        }

        $bytes = ByteSize::parse($typed);

        if (null === $bytes) {
            $form->get('fileLibraryQuota')->addError(new FormError($translator->trans('fileLibraryQuotaInvalidMessage')));

            return false;
        }

        $user->setFileLibraryQuotaBytes($bytes);

        return true;
    }

    /**
     * Takes over what edit()'s "Adresses Courrier école" section submitted: checks the typed
     * addresses, then settles which one is the primary.
     *
     * @param list<EmailAlias> $lockedAliases the non-administrable addresses as they stood before
     *                                        the submission
     *
     * @return bool false when an address was refused, the error being then hung on the offending row
     */
    private function applyEmailAliases(FormInterface $form, User $user, StudentMailAliasValidator $validator, TranslatorInterface $translator, array $lockedAliases): bool
    {
        // A row missing from the POST reads as a deletion to CollectionType. The template only ever
        // renders the button on hand-typed addresses; the others are put back rather than refused,
        // the screen having never offered to remove them in the first place.
        foreach ($lockedAliases as $lockedAlias) {
            $user->addEmailAlias($lockedAlias);
        }

        /** @var array<string, EmailAlias> $submitted */
        $submitted = [];
        foreach ($form->get('emailAliases') as $key => $child) {
            $submitted[$key] = $child->getData();
        }

        $violations = $validator->validate($user, $submitted);

        foreach ($violations as $key => $violation) {
            $form->get('emailAliases')->get($key)->get('localPart')->addError(
                new FormError($translator->trans($violation['message'], $violation['parameters'])),
            );
        }

        if ([] !== $violations) {
            return false;
        }

        $user->setPrimaryAlias($this->resolvePrimaryAlias($user, $submitted, FormValue::string($form, 'primaryAliasKey')));

        return true;
    }

    /**
     * Turns the checked row's key into the primary address, with a fallback: the student must come
     * out of the screen with a sending address for as long as they have one left, otherwise they'd
     * stop showing up as reachable anywhere (App\Entity\User::getSchoolMailAddress()).
     *
     * @param array<string, EmailAlias> $submitted
     */
    private function resolvePrimaryAlias(User $user, array $submitted, string $selectedKey): ?EmailAlias
    {
        $selected = $submitted[$selectedKey] ?? null;

        // The address taken from the login gets no radio button: it's the directory's short form,
        // not the one printed on a CV. A submission naming it anyway falls through to the fallback
        // below.
        if (null !== $selected && EmailAliasOrigin::Login !== $selected->getOrigin()) {
            return $selected;
        }

        $current = $user->getPrimaryAlias();

        if (null !== $current && $user->getEmailAliases()->contains($current)) {
            return $current;
        }

        foreach ($user->getEmailAliases() as $alias) {
            if (EmailAliasOrigin::Login !== $alias->getOrigin()) {
                return $alias;
            }
        }

        return $user->getEmailAliases()->first() ?: null;
    }

    // Désactiver le compte (design rule 5, admin only) - see App\Entity\User::$inactiveDate's
    // docblock; reversible, doesn't erase data (design rule 7). New route, as this account-status
    // concept didn't exist on the user profile screen before this handoff.
    #[Route(path: '/directory/users/{id}/deactivate', name: 'app_directory_users_deactivate', methods: ['POST'])]
    public function deactivate(Request $request, EntityManagerInterface $entityManager, UserRepository $repository, int $id): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $user = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('directory_user_deactivate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Nothing technical stops an administrator locking themselves out of the screen that would
        // let them back in - which is why it is stopped here. There is no second administrator to
        // count on: the platform has one.
        if ($currentUser === $user) {
            $this->addFlash('danger', 'userCannotDeactivateSelfFlashMessage');

            return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
        }

        $user->setInactiveDate(new \DateTimeImmutable());
        $user->setInactivatedBy($currentUser);
        $entityManager->flush();

        $this->addFlash('success', 'userDeactivatedFlashMessage');

        return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
    }

    // Symmetric counterpart to deactivate() above - not part of the design handoff's mockups
    // (only the active-account state was mocked up), but rule 7 explicitly calls deactivation
    // reversible, so a deactivated profile needs some way back without touching the database by
    // hand.
    #[Route(path: '/directory/users/{id}/reactivate', name: 'app_directory_users_reactivate', methods: ['POST'])]
    public function reactivate(Request $request, EntityManagerInterface $entityManager, UserRepository $repository, int $id): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $user = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('directory_user_reactivate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user->setInactiveDate(null);
        $user->setInactivatedBy(null);
        $entityManager->flush();

        $this->addFlash('success', 'userReactivatedFlashMessage');

        return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
    }

    #[Route(path: '/directory/users/data', name: 'app_directory_users_data')]
    public function data(Request $request, LdapManageUserRepository $repository, UserRepository $userRepository): JsonResponse
    {
        $params = DataTableParams::fromRequest($request);
        [$draw, $start, $length, $search] = [$params->draw, $params->start, $params->length, $params->search];

        $total = $repository->countAll();
        $filteredTotal = '' !== $search ? $repository->countAll($search) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null);

        // Historical queue rows predate DirectoryUserController::new() linking the User row up
        // front (see LdapManageUser::$user's docblock) and never got the FK backfilled, but the
        // account they describe still exists under the same login - resolve those by username so
        // the Modifier action (and "groupes manuels" column) isn't lost just because the FK is
        // empty. One batched lookup for the whole page instead of one query per row.
        $missingLogins = array_values(array_unique(array_filter(array_map(
            static fn (LdapManageUser $ldapUser): ?string => null === $ldapUser->getUser() ? $ldapUser->getLogin() : null,
            $rows,
        ))));
        $fallbackUsersByLogin = [];
        foreach ([] !== $missingLogins ? $userRepository->findBy(['username' => $missingLogins]) : [] as $fallbackUser) {
            $fallbackUsersByLogin[$fallbackUser->getUsername()] = $fallbackUser;
        }

        $data = [];
        foreach ($rows as $ldapUser) {
            $user = $ldapUser->getUser() ?? $fallbackUsersByLogin[$ldapUser->getLogin() ?? ''] ?? null;

            $data[] = [
                'id' => $user?->getId(),
                'fullName' => trim($ldapUser->getFirstname().' '.$ldapUser->getLastname()),
                'userType' => $ldapUser->getUserType(),
                'groups' => array_values(array_filter(explode('|', $ldapUser->getUserGroups()))),
                'manualGroups' => array_map(
                    static fn ($group): string => $group->getName(),
                    $user?->getManualGroups()->toArray() ?? [],
                ),
                // The Modifier action is hidden client-side for these - staff must not be able to
                // edit an admin profile from this list.
                'isAdmin' => \in_array('ROLE_ADMIN', $user?->getRoles() ?? [], true),
            ];
        }

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => $data,
        ]);
    }
}
