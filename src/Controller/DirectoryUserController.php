<?php

namespace App\Controller;

use App\Entity\LdapManagePassword;
use App\Entity\LdapManageUser;
use App\Entity\User;
use App\Form\LdapManageUserType;
use App\Form\UserProfileType;
use App\Repository\GroupRepository;
use App\Repository\LdapManageUserRepository;
use App\Repository\UserRepository;
use App\Service\ContactEmailVerifier;
use App\Service\LdapManageUserRoleResolver;
use App\Service\LoginGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
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
    public function index(): Response
    {
        return $this->render('directory/users.html.twig');
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
        LoginGenerator $loginGenerator,
        LdapManageUserRoleResolver $roleResolver,
        ContactEmailVerifier $contactEmailVerifier,
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
                $ldapUser->setAddedBy($currentUser->getUsername());

                $login = $loginGenerator->generate($ldapUser->getFirstname(), $ldapUser->getLastname());

                // LDAP-synced fields (email, firstname, lastname, roles) are pre-filled here to
                // what the account's first real LDAP login will set them to anyway (see
                // App\Security\LdapUserMapper) - not left blank/placeholder in the meantime.
                // DOMAIN matches create_user.sh's own "--mail=$login@$DOMAIN" in the ldap-manage
                // Scripts project.
                $user = new User($login);
                $user->setEmail($login.'@beaupeyrat.lan');
                $user->setFirstname($ldapUser->getFirstname());
                $user->setLastname($ldapUser->getLastname());
                $user->setRoles($roleResolver->resolve($ldapUser));
                $user->setContactEmail($contactEmail);
                $user->setPhoneNumber($form->get('phoneNumber')->getData());
                $user->setMustChangePassword($form->get('mustChangePassword')->getData());
                $user->setTestUser((bool) $form->get('testUser')->getData());
                // Staff creating the account is trusted outright - no confirmation mail, see
                // ContactEmailVerifier's docblock.
                $contactEmailVerifier->markVerifiedByStaff($user);

                // Also set on the queue row itself, not just the User - manage_user.php's
                // getUserLine() now reads this column directly instead of generating it (see that
                // Scripts-project function's docblock), so leaving it null here would hand it an
                // empty login to pass to create_user.sh.
                $ldapUser->setLogin($login);
                $ldapUser->setUser($user);

                $entityManager->persist($user);
                $entityManager->persist($ldapUser);
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

        $form = $this->createForm(UserProfileType::class, $user, [
            'adGroupNames' => $adGroupNames,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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

        return $this->render('directory/user_form.html.twig', [
            'form' => $form,
            'editedUser' => $user,
            'resolvedType' => $roleResolver->resolveTypeFromRoles($ldapRoles),
            'adGroupBuckets' => $adGroupBuckets,
            'manualGroupBuckets' => $groupRepository->findManuallyAssignableGroupedByType($adGroupNames),
        ]);
    }

    // Réinitialiser le mot de passe (design/design_handoff_utilisateurs/README.md rule 5, admin
    // only) - queues a request the same way Directory > Mots de passe's own "new" action does
    // (App\Controller\DirectoryPasswordController::new()), just pre-targeted at this user instead
    // of going through that screen's tom-select picker.
    #[Route(path: '/directory/users/{id}/reset-password', name: 'app_directory_users_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, EntityManagerInterface $entityManager, UserRepository $repository, int $id): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $user = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('directory_user_reset_password', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ldapManagePassword = new LdapManagePassword($user);
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $ldapManagePassword->setAddedBy($currentUser->getUsername());

        $entityManager->persist($ldapManagePassword);
        $entityManager->flush();

        $this->addFlash('success', 'passwordResetRequestedFlashMessage');

        return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
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
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));

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
