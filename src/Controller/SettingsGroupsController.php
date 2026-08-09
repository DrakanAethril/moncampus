<?php

namespace App\Controller;

use App\Entity\Group;
use App\Entity\GroupType as GroupTypeEntity;
use App\Entity\User;
use App\Form\GroupType;
use App\Form\GroupTypeType;
use App\Repository\GroupRepository;
use App\Repository\GroupTypeRepository;
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
    // Tabbed since GroupType was added (see App\Entity\GroupType) - "Groupes" is unchanged, the
    // "Types de groupe" tab manages the purely-cosmetic category groups can optionally be
    // attached to. Same card-attached-tabs pattern as Configuration/Pédagogique/UFA - see
    // templates/settings/groups.html.twig and design/design_campus_manager/README.md 8a/9a.
    #[Route(path: '/settings/groups', name: 'app_settings_groups')]
    public function index(): Response
    {
        return $this->render('settings/groups.html.twig', ['activeTab' => 'groups']);
    }

    #[Route(path: '/settings/groups/types', name: 'app_settings_group_types')]
    public function groupTypesTab(GroupTypeRepository $repository): Response
    {
        return $this->render('settings/groups.html.twig', [
            'activeTab' => 'group_types',
            'groupTypes' => $repository->findAllOrdered(),
        ]);
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
        $this->assertValidToken($request, 'settings_groups_deactivate');

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
                    'groupTypeName' => $group->getGroupType()?->getName() ?? '—',
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

    #[Route(path: '/settings/groups/types/new', name: 'app_settings_group_types_new')]
    public function newGroupType(Request $request, EntityManagerInterface $entityManager, GroupTypeRepository $repository): Response
    {
        $form = $this->createForm(GroupTypeType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $groupType = $form->getData();
            $groupType->setCreatedBy($this->currentUser());
            $groupType->setOrder(\count($repository->findAllOrdered()) + 1);

            $entityManager->persist($groupType);
            $entityManager->flush();

            $this->addFlash('success', 'groupTypeCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_group_types');
        }

        return $this->render('settings/group_type_new.html.twig', [
            'form' => $form,
            'isEdit' => false,
        ]);
    }

    #[Route(path: '/settings/groups/types/{id}/edit', name: 'app_settings_group_types_edit')]
    public function editGroupType(Request $request, EntityManagerInterface $entityManager, GroupTypeRepository $repository, int $id): Response
    {
        $groupType = $this->findGroupTypeOrNotFound($repository, $id);

        $form = $this->createForm(GroupTypeType::class, $groupType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $groupType->setLastUpdatedBy($this->currentUser());
            $groupType->setLastUpdatedDate(new \DateTimeImmutable());

            $entityManager->flush();

            $this->addFlash('success', 'groupTypeUpdatedFlashMessage');

            return $this->redirectToRoute('app_settings_group_types');
        }

        return $this->render('settings/group_type_new.html.twig', [
            'form' => $form,
            'isEdit' => true,
        ]);
    }

    #[Route(path: '/settings/groups/types/{id}/deactivate', name: 'app_settings_group_types_deactivate', methods: ['POST'])]
    public function deactivateGroupType(Request $request, EntityManagerInterface $entityManager, GroupTypeRepository $repository, int $id): JsonResponse
    {
        $groupType = $this->findGroupTypeOrNotFound($repository, $id);
        $this->assertValidToken($request, 'settings_group_types_deactivate');

        $groupType->setInactiveDate(new \DateTimeImmutable());
        $groupType->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    // Mirrors App\Controller\SequenceLibraryController::sequencesReorder() - re-fetches the
    // canonical, position-ordered list so a stale/malicious POST can't smuggle in ids that don't
    // belong, indexes it by id, then walks the dragged list's new order applying 1-based
    // positions. Full list (active + inactive) since the reorderable list on
    // /settings/groups/types shows both when "show inactive" is toggled on.
    #[Route(path: '/settings/groups/types/reorder', name: 'app_settings_group_types_reorder', methods: ['POST'])]
    public function reorderGroupTypes(Request $request, EntityManagerInterface $entityManager, GroupTypeRepository $repository): JsonResponse
    {
        $this->assertValidToken($request, 'settings_group_types_reorder');

        $groupTypesById = [];
        foreach ($repository->findAllOrdered() as $groupType) {
            $groupTypesById[$groupType->getId()] = $groupType;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $ids = \is_array($data['ids'] ?? null) ? array_map(intval(...), $data['ids']) : [];

        foreach ($ids as $position => $groupTypeId) {
            ($groupTypesById[$groupTypeId] ?? null)?->setOrder($position + 1);
        }

        $entityManager->flush();

        return $this->json(['success' => true]);
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

    private function findGroupTypeOrNotFound(GroupTypeRepository $repository, int $id): GroupTypeEntity
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function assertValidToken(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->headers->get('X-CSRF-Token'))) {
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
