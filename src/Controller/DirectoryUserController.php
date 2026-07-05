<?php

namespace App\Controller;

use App\Entity\LdapManageUser;
use App\Entity\User;
use App\Form\LdapManageUserType;
use App\Repository\LdapManageUserRepository;
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

    #[Route(path: '/directory/users/new', name: 'app_directory_users_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        // Only account creation is supported from this form; password-change requests
        // (the other action_type the consumer script handles) aren't created this way.
        $ldapUser = new LdapManageUser('', '', '', 'account_create');
        $form = $this->createForm(LdapManageUserType::class, $ldapUser);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $currentUser */
            $currentUser = $this->getUser();
            $ldapUser->setAddedBy($currentUser->getUsername());

            $entityManager->persist($ldapUser);
            $entityManager->flush();

            $this->addFlash('success', 'userCreatedFlashMessage');

            return $this->redirectToRoute('app_directory_users');
        }

        return $this->render('directory/user_new.html.twig', [
            'form' => $form,
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
