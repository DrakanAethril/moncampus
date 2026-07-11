<?php

namespace App\Controller;

use App\Entity\InternshipBehaviorCriteria;
use App\Entity\InternshipBehaviorLevel;
use App\Entity\InternshipSkillLevel;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\User;
use App\Form\InternshipBehaviorCriteriaType;
use App\Form\InternshipFormationCenterType;
use App\Form\InternshipSkillLevelType;
use App\Form\SkillGroupType;
use App\Form\SkillType;
use App\Repository\InternshipBehaviorCriteriaRepository;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\InternshipSkillLevelRepository;
use App\Repository\OptionRepository;
use App\Repository\SkillGroupRepository;
use App\Repository\SkillRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The "Livret Alternant" settings area, reached via its own entry in the global "Paramètres"
// dropdown - kept as a sibling of SettingsStructureController rather than more tabs stuffed
// into templates/settings/structure.html.twig, which is already dense with 10 tabs.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SettingsInternshipController extends AbstractController
{
    #[Route(path: '/settings/internship', name: 'app_settings_internship')]
    #[Route(path: '/settings/internship/formation-center', name: 'app_settings_internship_formation_center')]
    public function formationCenterTab(Request $request, EntityManagerInterface $entityManager, InternshipFormationCenterRepository $repository): Response
    {
        $formationCenter = $repository->getOrCreate();

        if (null === $formationCenter->getCreatedBy()) {
            $formationCenter->setCreatedBy($this->currentUser());
        }

        $form = $this->createForm(InternshipFormationCenterType::class, $formationCenter);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formationCenter->setLastUpdatedBy($this->currentUser());
            $formationCenter->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'internshipFormationCenterUpdatedFlashMessage');

            return $this->redirectToRoute('app_settings_internship_formation_center');
        }

        return $this->render('settings/internship.html.twig', [
            'activeTab' => 'formation_center',
            'form' => $form,
        ]);
    }

    #[Route(path: '/settings/internship/behavior', name: 'app_settings_internship_behavior')]
    public function behaviorTab(): Response
    {
        return $this->render('settings/internship.html.twig', ['activeTab' => 'behavior']);
    }

    #[Route(path: '/settings/internship/behavior/new', name: 'app_settings_internship_behavior_new')]
    #[Route(path: '/settings/internship/behavior/{id}/edit', name: 'app_settings_internship_behavior_edit')]
    public function behaviorCriteriaForm(Request $request, EntityManagerInterface $entityManager, InternshipBehaviorCriteriaRepository $repository, ?int $id = null): Response
    {
        $isEdit = null !== $id;
        $criteria = $isEdit ? $this->findOrNotFound($repository, $id) : new InternshipBehaviorCriteria();

        if (!$isEdit) {
            for ($levelNumber = 1; $levelNumber <= 5; ++$levelNumber) {
                $criteria->addLevel(new InternshipBehaviorLevel($levelNumber));
            }
        }

        $form = $this->createForm(InternshipBehaviorCriteriaType::class, $criteria);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipBehaviorUpdatedFlashMessage' : 'internshipBehaviorCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_internship_behavior');
        }

        return $this->render('settings/internship_behavior_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/internship/behavior/{id}/deactivate', name: 'app_settings_internship_behavior_deactivate', methods: ['POST'])]
    public function deactivateBehaviorCriteria(Request $request, EntityManagerInterface $entityManager, InternshipBehaviorCriteriaRepository $repository, int $id): JsonResponse
    {
        $criteria = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $criteria->setInactiveDate(new \DateTimeImmutable());
        $criteria->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/internship/behavior/data', name: 'app_settings_internship_behavior_data')]
    public function behaviorCriteriaData(Request $request, InternshipBehaviorCriteriaRepository $repository): JsonResponse
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
                fn (InternshipBehaviorCriteria $criteria): array => [
                    'id' => $criteria->getId(),
                    'isInactive' => null !== $criteria->getInactiveDate(),
                    'label' => $criteria->getLabel(),
                    'orderIndex' => $criteria->getOrderIndex(),
                    'creationDate' => $criteria->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $criteria->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($criteria->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($criteria->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($criteria->getLastUpdatedBy()),
                    'lastUpdatedDate' => $criteria->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/settings/internship/skill-levels', name: 'app_settings_internship_skill_levels')]
    public function skillLevelsTab(): Response
    {
        return $this->render('settings/internship.html.twig', ['activeTab' => 'skill_levels']);
    }

    #[Route(path: '/settings/internship/skill-levels/new', name: 'app_settings_internship_skill_levels_new')]
    #[Route(path: '/settings/internship/skill-levels/{id}/edit', name: 'app_settings_internship_skill_levels_edit')]
    public function skillLevelForm(Request $request, EntityManagerInterface $entityManager, InternshipSkillLevelRepository $repository, ?int $id = null): Response
    {
        $skillLevel = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $skillLevel;

        $form = $this->createForm(InternshipSkillLevelType::class, $skillLevel ?? new InternshipSkillLevel());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipSkillLevelUpdatedFlashMessage' : 'internshipSkillLevelCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_internship_skill_levels');
        }

        return $this->render('settings/internship_skill_level_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/internship/skill-levels/{id}/deactivate', name: 'app_settings_internship_skill_levels_deactivate', methods: ['POST'])]
    public function deactivateSkillLevel(Request $request, EntityManagerInterface $entityManager, InternshipSkillLevelRepository $repository, int $id): JsonResponse
    {
        $skillLevel = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $skillLevel->setInactiveDate(new \DateTimeImmutable());
        $skillLevel->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/internship/skill-levels/data', name: 'app_settings_internship_skill_levels_data')]
    public function skillLevelsData(Request $request, InternshipSkillLevelRepository $repository): JsonResponse
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
                fn (InternshipSkillLevel $skillLevel): array => [
                    'id' => $skillLevel->getId(),
                    'isInactive' => null !== $skillLevel->getInactiveDate(),
                    'label' => $skillLevel->getLabel(),
                    'color' => $skillLevel->getColor(),
                    'orderIndex' => $skillLevel->getOrderIndex(),
                    'creationDate' => $skillLevel->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $skillLevel->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($skillLevel->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($skillLevel->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($skillLevel->getLastUpdatedBy()),
                    'lastUpdatedDate' => $skillLevel->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    // The Centre de formation's own shared SkillGroup/Skill definition (program IS NULL,
    // see SkillGroup::isGlobal()) - every Program uses this by default, unless it opts into
    // Program::$customSkillCriteriaEnabled and gets its own rows managed at
    // ProgramSettingsController::skillGroupsTab() instead.
    #[Route(path: '/settings/internship/skill-groups', name: 'app_settings_internship_skill_groups')]
    public function skillGroupsTab(): Response
    {
        return $this->render('settings/internship.html.twig', ['activeTab' => 'skill_groups']);
    }

    #[Route(path: '/settings/internship/skill-groups/new', name: 'app_settings_internship_skill_groups_new')]
    #[Route(path: '/settings/internship/skill-groups/{groupId}/edit', name: 'app_settings_internship_skill_groups_edit')]
    public function skillGroupForm(Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, OptionRepository $optionRepository, ?int $groupId = null): Response
    {
        $isEdit = null !== $groupId;
        // A real SkillGroup backs the "new" form too, not null - same reasoning as
        // ProgramSettingsController::skillGroupForm().
        $skillGroup = $isEdit ? $this->findGlobalSkillGroupOrNotFound($skillGroupRepository, $groupId) : new SkillGroup('');

        $form = $this->createForm(SkillGroupType::class, $skillGroup, ['optionChoices' => $optionRepository->findAllActiveOrderedByName()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipSkillGroupUpdatedFlashMessage' : 'internshipSkillGroupCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_internship_skill_groups');
        }

        return $this->render('settings/internship_skill_group_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/internship/skill-groups/{groupId}/deactivate', name: 'app_settings_internship_skill_groups_deactivate', methods: ['POST'])]
    public function deactivateSkillGroup(Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, int $groupId): JsonResponse
    {
        $skillGroup = $this->findGlobalSkillGroupOrNotFound($skillGroupRepository, $groupId);
        $this->assertValidDeactivateToken($request);

        $skillGroup->setInactiveDate(new \DateTimeImmutable());
        $skillGroup->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/internship/skill-groups/data', name: 'app_settings_internship_skill_groups_data')]
    public function skillGroupsData(Request $request, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $skillGroupRepository->countAllGlobal(null, $includeInactive);
        $filteredTotal = '' !== $search ? $skillGroupRepository->countAllGlobal($search, $includeInactive) : $total;
        $rows = $skillGroupRepository->findPageGlobalOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (SkillGroup $skillGroup): array => [
                    'id' => $skillGroup->getId(),
                    'isInactive' => null !== $skillGroup->getInactiveDate(),
                    // Rendered as trusted HTML by the 'html' render keyword on this column (see
                    // _skill_groups_content.html.twig) - the default column render escapes it.
                    'label' => sprintf(
                        '<a href="%s">%s</a>',
                        htmlspecialchars($this->generateUrl('app_settings_internship_skill_groups_skills', ['groupId' => $skillGroup->getId()])),
                        htmlspecialchars($skillGroup->getLabel()),
                    ),
                    'creationDate' => $skillGroup->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $skillGroup->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($skillGroup->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($skillGroup->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($skillGroup->getLastUpdatedBy()),
                    'lastUpdatedDate' => $skillGroup->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/settings/internship/skill-groups/{groupId}/skills', name: 'app_settings_internship_skill_groups_skills')]
    public function skillsList(SkillGroupRepository $skillGroupRepository, int $groupId): Response
    {
        $skillGroup = $this->findGlobalSkillGroupOrNotFound($skillGroupRepository, $groupId);

        return $this->render('settings/internship_skill_group_skills.html.twig', ['skillGroup' => $skillGroup]);
    }

    #[Route(path: '/settings/internship/skill-groups/{groupId}/skills/new', name: 'app_settings_internship_skill_groups_skills_new')]
    #[Route(path: '/settings/internship/skill-groups/{groupId}/skills/{skillId}/edit', name: 'app_settings_internship_skill_groups_skills_edit')]
    public function skillForm(Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository, int $groupId, ?int $skillId = null): Response
    {
        $skillGroup = $this->findGlobalSkillGroupOrNotFound($skillGroupRepository, $groupId);
        $isEdit = null !== $skillId;
        $skill = $isEdit ? $this->findSkillOrNotFound($skillRepository, $skillGroup, $skillId) : new Skill('', $skillGroup);

        $form = $this->createForm(SkillType::class, $skill);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'skillUpdatedFlashMessage' : 'skillCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_internship_skill_groups_skills', ['groupId' => $skillGroup->getId()]);
        }

        return $this->render('settings/internship_skill_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'skillGroup' => $skillGroup,
        ]);
    }

    #[Route(path: '/settings/internship/skill-groups/{groupId}/skills/{skillId}/deactivate', name: 'app_settings_internship_skill_groups_skills_deactivate', methods: ['POST'])]
    public function deactivateSkill(Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository, int $groupId, int $skillId): JsonResponse
    {
        $skillGroup = $this->findGlobalSkillGroupOrNotFound($skillGroupRepository, $groupId);
        $skill = $this->findSkillOrNotFound($skillRepository, $skillGroup, $skillId);
        $this->assertValidDeactivateToken($request);

        $skill->setInactiveDate(new \DateTimeImmutable());
        $skill->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/internship/skill-groups/{groupId}/skills/data', name: 'app_settings_internship_skill_groups_skills_data')]
    public function skillsData(Request $request, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository, int $groupId): JsonResponse
    {
        $skillGroup = $this->findGlobalSkillGroupOrNotFound($skillGroupRepository, $groupId);
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $skillRepository->countAllForSkillGroup($skillGroup, null, $includeInactive);
        $filteredTotal = '' !== $search ? $skillRepository->countAllForSkillGroup($skillGroup, $search, $includeInactive) : $total;
        $rows = $skillRepository->findPageForSkillGroupOrderedByMostRecent($skillGroup, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Skill $skill): array => [
                    'id' => $skill->getId(),
                    'isInactive' => null !== $skill->getInactiveDate(),
                    'label' => $skill->getLabel(),
                    'creationDate' => $skill->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $skill->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($skill->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($skill->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($skill->getLastUpdatedBy()),
                    'lastUpdatedDate' => $skill->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    private function findGlobalSkillGroupOrNotFound(SkillGroupRepository $repository, int $groupId): SkillGroup
    {
        $skillGroup = $repository->find($groupId) ?? throw $this->createNotFoundException();

        if (!$skillGroup->isGlobal()) {
            throw $this->createNotFoundException();
        }

        return $skillGroup;
    }

    private function findSkillOrNotFound(SkillRepository $repository, SkillGroup $skillGroup, int $skillId): Skill
    {
        $skill = $repository->find($skillId) ?? throw $this->createNotFoundException();

        if ($skill->getSkillGroup()?->getId() !== $skillGroup->getId()) {
            throw $this->createNotFoundException();
        }

        return $skill;
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

    /**
     * @template T of object
     *
     * @param ObjectRepository<T> $repository
     *
     * @return T
     */
    private function findOrNotFound(ObjectRepository $repository, int $id): object
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }

    private function assertValidDeactivateToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('internship_deactivate', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
