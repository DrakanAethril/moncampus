<?php

namespace App\Controller;

use App\Entity\LdapManageUser;
use App\Entity\User;
use App\Form\LdapManageUserType;
use App\Repository\GroupRepository;
use App\Repository\LdapManageUserRepository;
use App\Service\ContactEmailVerifier;
use App\Service\LdapManageUserRoleResolver;
use App\Service\LoginGenerator;
use App\Service\QueueStateFormatter;
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
    // usable - via App\Security\MagicLinkAuthenticator or the contact-email-confirmation
    // auto-login below - before LDAP has actually provisioned it, which can take anywhere from
    // minutes to days depending on when the consumer script next runs. See LoginGenerator's
    // docblock for why login uniqueness must now be checked before this point, not after.
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
            }

            $ldapUser->setUser($user);

            $entityManager->persist($user);
            $entityManager->persist($ldapUser);
            $entityManager->flush();

            // Sent only once the row above is safely committed - a failed flush must never
            // result in a confirmation email for an account that doesn't exist.
            if (null !== $contactEmail) {
                $contactEmailVerifier->requestVerification($user);
                $entityManager->flush();
            }

            $this->addFlash('success', 'userCreatedFlashMessage');

            return $this->redirectToRoute('app_directory_users');
        }

        return $this->render('directory/user_new.html.twig', [
            'form' => $form,
            // Same excluded-names list LdapManageUserType::availableSecondaryGroups() passes for
            // the form's own choices - kept in one place (LdapManageUserType::excludedGroupNames())
            // so the chips rendered here can never include a group the form itself would reject.
            'groupBuckets' => $groupRepository->findAllActiveGroupedByType(LdapManageUserType::excludedGroupNames()),
        ]);
    }

    #[Route(path: '/directory/users/data', name: 'app_directory_users_data')]
    public function data(Request $request, LdapManageUserRepository $repository, QueueStateFormatter $stateFormatter): JsonResponse
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
                fn (LdapManageUser $user): array => [
                    'fullName' => trim($user->getFirstname().' '.$user->getLastname()),
                    'userType' => $user->getUserType(),
                    'groups' => array_values(array_filter(explode('|', $user->getUserGroups()))),
                    'actionType' => $user->getActionType(),
                    'login' => $user->getLogin(),
                    'statusLabel' => $stateFormatter->label($user->getState()),
                    'statusClass' => $stateFormatter->cssClass($user->getState()),
                    'addedAt' => $user->getAddedAt()->format('d/m/Y H:i'),
                ],
                $rows,
            ),
        ]);
    }
}
