<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserProfileType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Gestion > Utilisateurs - editing a User's local-only fields (contact email, phone, manually
// assigned groups). Deliberately separate from Directory (App\Controller\DirectoryUserController
// et al., which only manage the LDAP account-request queue, nothing local) - this is plain,
// immediate DB writes, no LDAP queue involved at all. Same guard as Directory (admin/staff/
// staff-lead), unlike Settings > Groups which is admin-only.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class UserManagementController extends AbstractController
{
    #[Route(path: '/users', name: 'app_users')]
    public function index(): Response
    {
        return $this->render('users/index.html.twig');
    }

    #[Route(path: '/users/{id}/edit', name: 'app_users_edit')]
    public function edit(Request $request, EntityManagerInterface $entityManager, UserRepository $repository, int $id): Response
    {
        $user = $repository->find($id) ?? throw $this->createNotFoundException();

        $form = $this->createForm(UserProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'userProfileUpdatedFlashMessage');

            return $this->redirectToRoute('app_users');
        }

        return $this->render('users/edit.html.twig', [
            'form' => $form,
            'editedUser' => $user,
        ]);
    }

    #[Route(path: '/users/data', name: 'app_users_data')]
    public function data(Request $request, UserRepository $repository): JsonResponse
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));

        $total = $repository->countAllForListing();
        $filteredTotal = '' !== $search ? $repository->countAllForListing($search) : $total;
        $rows = $repository->findPageForListing($start, $length, '' !== $search ? $search : null);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (User $user): array => [
                    'id' => $user->getId(),
                    'displayName' => $user->getDisplayName() ?? $user->getUsername(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail() ?? '—',
                    'contactEmail' => $user->getContactEmail() ?? '—',
                    'phoneNumber' => $user->getPhoneNumber() ?? '—',
                    'manualGroups' => implode(', ', array_map(
                        static fn ($group): string => $group->getName(),
                        $user->getManualGroups()->toArray(),
                    )) ?: '—',
                ],
                $rows,
            ),
        ]);
    }
}
