<?php

namespace App\Controller;

use App\Entity\LdapManagePassword;
use App\Entity\User;
use App\Form\LdapManagePasswordType;
use App\Repository\LdapManagePasswordRepository;
use App\Repository\UserRepository;
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
class DirectoryPasswordController extends AbstractController
{
    #[Route(path: '/directory/passwords', name: 'app_directory_passwords')]
    public function index(): Response
    {
        return $this->render('directory/passwords.html.twig');
    }

    #[Route(path: '/directory/passwords/new', name: 'app_directory_passwords_new')]
    public function new(Request $request, EntityManagerInterface $entityManager, UserRepository $userRepository): Response
    {
        $form = $this->createForm(LdapManagePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Submitted outside the form's own namespace by the tom-select picker - see
            // LdapManagePasswordType's docblock.
            $targetUser = $userRepository->find($request->request->getInt('user')) ?? throw $this->createNotFoundException();

            $ldapManagePassword = new LdapManagePassword($targetUser);
            /** @var User $currentUser */
            $currentUser = $this->getUser();
            $ldapManagePassword->setAddedBy($currentUser->getUsername());

            $entityManager->persist($ldapManagePassword);
            $entityManager->flush();

            $this->addFlash('success', 'passwordResetRequestedFlashMessage');

            return $this->redirectToRoute('app_directory_passwords');
        }

        return $this->render('directory/password_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/directory/passwords/data', name: 'app_directory_passwords_data')]
    public function data(Request $request, LdapManagePasswordRepository $repository, QueueStateFormatter $stateFormatter): JsonResponse
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
                fn (LdapManagePassword $ldapManagePassword): array => [
                    'id' => $ldapManagePassword->getId(),
                    'fullName' => $ldapManagePassword->getUser()->getDisplayName() ?? $ldapManagePassword->getUser()->getUsername(),
                    'login' => $ldapManagePassword->getLogin(),
                    'statusLabel' => $stateFormatter->label($ldapManagePassword->getState()),
                    'statusClass' => $stateFormatter->cssClass($ldapManagePassword->getState()),
                    'addedAt' => $ldapManagePassword->getAddedAt()->format('d/m/Y H:i'),
                    'canReveal' => 2 === $ldapManagePassword->getState(),
                ],
                $rows,
            ),
        ]);
    }

    // Backs the tom-select ajax widget for the "target user" picker (see LdapManagePasswordType's
    // docblock) - any active user is a valid target, so this searches the whole directory rather
    // than a role-scoped subset.
    #[Route(path: '/directory/passwords/user-search', name: 'app_directory_passwords_user_search')]
    public function userSearch(Request $request, UserRepository $userRepository): JsonResponse
    {
        $limit = 20;
        $users = $userRepository->searchActive($request->query->get('q'), $limit);

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], $users),
            'pagination' => ['more' => \count($users) === $limit],
        ]);
    }

    // Decrypts and returns the generated password once the external consumer script has
    // succeeded - see LdapManagePasswordRepository::decryptPassword() for why this can only ever
    // return something for a row in the "succeeded" state.
    #[Route(path: '/directory/passwords/{id}/reveal', name: 'app_directory_passwords_reveal', methods: ['POST'])]
    public function reveal(int $id, Request $request, LdapManagePasswordRepository $repository): JsonResponse
    {
        if (!$this->isCsrfTokenValid('directory_password_reveal', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ldapManagePassword = $repository->find($id) ?? throw $this->createNotFoundException();
        $password = $repository->decryptPassword($ldapManagePassword) ?? throw $this->createNotFoundException();

        return $this->json(['password' => $password]);
    }
}
