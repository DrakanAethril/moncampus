<?php

namespace App\Controller;

use App\Entity\Group;
use App\Entity\User;
use App\Form\GroupType;
use App\Repository\GroupRepository;
use App\Service\LdapGroupSyncer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

// Admin-only, deliberately stricter than the rest of Settings (which also allows
// staff/staff-lead) - managing which groups (LDAP-mirrored or local-only) can grant a role is a
// higher-stakes action than the rest of this app's structural/reference data.
#[IsGranted('ROLE_ADMIN')]
class SettingsGroupsController extends AbstractController
{
    #[Route(path: '/settings/groups', name: 'app_settings_groups')]
    public function index(): Response
    {
        return $this->render('settings/groups.html.twig');
    }

    #[Route(path: '/settings/groups/new', name: 'app_settings_groups_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(GroupType::class, null, ['isLdapSynced' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $group = $form->getData();
            $group->setCreatedBy($this->currentUser());

            $entityManager->persist($group);
            $entityManager->flush();

            $this->addFlash('success', 'groupCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_groups');
        }

        return $this->render('settings/group_new.html.twig', [
            'form' => $form,
            'isEdit' => false,
        ]);
    }

    #[Route(path: '/settings/groups/{id}/edit', name: 'app_settings_groups_edit')]
    public function edit(Request $request, EntityManagerInterface $entityManager, GroupRepository $repository, int $id): Response
    {
        $group = $this->findOrNotFound($repository, $id);

        $form = $this->createForm(GroupType::class, $group, ['isLdapSynced' => $group->isLdapSynced()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $group->setLastUpdatedBy($this->currentUser());
            $group->setLastUpdatedDate(new \DateTimeImmutable());

            $entityManager->flush();

            $this->addFlash('success', 'groupUpdatedFlashMessage');

            return $this->redirectToRoute('app_settings_groups');
        }

        return $this->render('settings/group_new.html.twig', [
            'form' => $form,
            'isEdit' => true,
        ]);
    }

    #[Route(path: '/settings/groups/{id}/deactivate', name: 'app_settings_groups_deactivate', methods: ['POST'])]
    public function deactivate(Request $request, EntityManagerInterface $entityManager, GroupRepository $repository, int $id): JsonResponse
    {
        $group = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $group->setInactiveDate(new \DateTimeImmutable());
        $group->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/groups/sync', name: 'app_settings_groups_sync', methods: ['POST'])]
    public function sync(Request $request, LdapGroupSyncer $syncer): JsonResponse
    {
        if (!$this->isCsrfTokenValid('settings_groups_sync', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return $this->json(['createdCount' => $syncer->sync($this->currentUser())]);
    }

    #[Route(path: '/settings/groups/data', name: 'app_settings_groups_data')]
    public function data(Request $request, GroupRepository $repository, TranslatorInterface $translator): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Group $group): array => [
                    'id' => $group->getId(),
                    'isInactive' => null !== $group->getInactiveDate(),
                    'name' => $group->getName(),
                    'role' => $group->getRole(),
                    'sourceLabel' => $translator->trans($group->isLdapSynced() ? 'groupSourceLdapLabel' : 'groupSourceLocalLabel'),
                    'manuallyAssignableLabel' => $translator->trans($group->isManuallyAssignable() ? 'yesLabel' : 'noLabel'),
                    'creationDate' => $group->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $group->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($group->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($group->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($group->getLastUpdatedBy()),
                    'lastUpdatedDate' => $group->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function userLabel(?User $user): string
    {
        if (null === $user) {
            return '—';
        }

        return $user->getDisplayName() ?? $user->getUsername();
    }

    private function findOrNotFound(GroupRepository $repository, int $id): Group
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function assertValidDeactivateToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('settings_groups_deactivate', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readDataTableParams(Request $request): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));
        $includeInactive = $request->query->getBoolean('includeInactive');

        return [$draw, $start, $length, $search, $includeInactive];
    }
}
