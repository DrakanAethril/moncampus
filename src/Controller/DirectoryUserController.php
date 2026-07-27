<?php

namespace App\Controller;

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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        LoginGenerator $loginGenerator,
        LdapManageUserRoleResolver $roleResolver,
        ContactEmailVerifier $contactEmailVerifier,
    ): Response {
        // Only account creation is supported from this form; password-change requests go through
        // the separate Directory > Mots de passe screen (App\Controller\DirectoryPasswordController),
        // backed by App\Entity\LdapManagePassword instead of this queue.
        $ldapUser = new LdapManageUser('', '', '', 'account_create');
        $form = $this->createForm(LdapManageUserType::class, $ldapUser);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $currentUser */
            $currentUser = $this->getUser();
            $ldapUser->setAddedBy($currentUser->getUsername());

            $contactEmail = $form->get('contactEmail')->getData();
            $login = $loginGenerator->generate($ldapUser->getFirstname(), $ldapUser->getLastname());

            // LDAP-synced fields (email, firstname, lastname, roles) are pre-filled here to what
            // the account's first real LDAP login will set them to anyway (see
            // App\Security\LdapUserMapper) - not left blank/placeholder in the meantime. DOMAIN
            // matches create_user.sh's own "--mail=$login@$DOMAIN" in the ldap-manage Scripts
            // project.
            $user = new User($login);
            $user->setEmail($login.'@beaupeyrat.lan');
            $user->setFirstname($ldapUser->getFirstname());
            $user->setLastname($ldapUser->getLastname());
            $user->setRoles($roleResolver->resolve($ldapUser));

            // Also set on the queue row itself, not just the User - manage_user.php's
            // getUserLine() now reads this column directly instead of generating it (see that
            // Scripts-project function's docblock), so leaving it null here would hand it an
            // empty login to pass to create_user.sh.
            $ldapUser->setLogin($login);

            if (null !== $contactEmail) {
                $user->setContactEmail($contactEmail);
                // Staff creating the account is trusted outright - no confirmation mail, see
                // ContactEmailVerifier's docblock.
                $contactEmailVerifier->markVerifiedByStaff($user);
            }

            $ldapUser->setUser($user);

            $entityManager->persist($user);
            $entityManager->persist($ldapUser);
            $entityManager->flush();

            $this->addFlash('success', 'userCreatedFlashMessage');

            return $this->redirectToRoute('app_directory_users');
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
        $previousEmail = $user->getContactEmail();

        $groupBuckets = $groupRepository->findAllActiveGroupedByType(LdapManageUserType::excludedGroupNames());
        $ldapRoles = $user->getLdapRoles();
        $adGroupNames = [];
        foreach ($groupBuckets as $bucket) {
            foreach ($bucket['groups'] as $group) {
                if (\in_array($group->getRole(), $ldapRoles, true)) {
                    $adGroupNames[] = $group->getName();
                }
            }
        }

        $form = $this->createForm(UserProfileType::class, $user, [
            'resolvedType' => $roleResolver->resolveTypeFromRoles($ldapRoles),
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
            'groupBuckets' => $groupBuckets,
        ]);
    }

    #[Route(path: '/directory/users/data', name: 'app_directory_users_data')]
    public function data(Request $request, LdapManageUserRepository $repository): JsonResponse
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));

        $total = $repository->countAll();
        $filteredTotal = '' !== $search ? $repository->countAll($search) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (LdapManageUser $ldapUser): array => [
                    // Null for a queue row with no linked User (see LdapManageUser::$user's
                    // docblock - historical rows predating that link) - the Modifier action and
                    // "groupes manuels" column below both come from that live User, not from
                    // anything stored on the queue row itself, so both simply stay empty for those.
                    'id' => $ldapUser->getUser()?->getId(),
                    'fullName' => trim($ldapUser->getFirstname().' '.$ldapUser->getLastname()),
                    'userType' => $ldapUser->getUserType(),
                    'groups' => array_values(array_filter(explode('|', $ldapUser->getUserGroups()))),
                    'manualGroups' => array_map(
                        static fn ($group): string => $group->getName(),
                        $ldapUser->getUser()?->getManualGroups()->toArray() ?? [],
                    ),
                ],
                $rows,
            ),
        ]);
    }
}
