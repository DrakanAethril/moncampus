<?php

namespace App\Controller\Program;

use App\Entity\Program;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\User;
use App\Form\SkillGroupType;
use App\Form\SkillType;
use App\Repository\ProgramRepository;
use App\Repository\SkillGroupRepository;
use App\Repository\SkillRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Formation > Paramétrage, onglet « Groupes de compétences » et les compétences que chaque groupe contient (SkillGroup::\$skills). Toujours propres à la formation : il n'existe pas de variante partagée, contrairement aux niveaux.
 *
 * Split out of the former ProgramSettingsController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SettingsSkillGroupController extends AbstractController
{
    use ProgramSettingsTabTrait;

    #[Route(path: '/programs/{id}/settings/skill-groups', name: 'app_program_settings_skill_groups')]
    public function skillGroupsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'skill_groups');
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/new', name: 'app_program_settings_skill_groups_new')]
    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/edit', name: 'app_program_settings_skill_groups_edit')]
    public function skillGroupForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository, ?int $groupId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $isEdit = null !== $groupId;
        // A real SkillGroup backs the "new" form too, not null - visibleInBooklet/
        // visibleInProgram are ordinary mapped checkboxes that read their initial view state
        // straight off the model, so only a real instance (picking up the `= true` property
        // defaults) renders them pre-checked, same reasoning as ProgramType's management-enabled
        // checkboxes in Settings\ProgramController::programForm().
        $skillGroup = $isEdit ? $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId) : new SkillGroup('', $program);

        // Optional "teacher" is picked via an ajax tom-select field embedded directly in
        // skill_group_new.html.twig (not a mapped SkillGroupType field) and resolved here, same
        // convention as reportForm()'s referee - only the program's own teachers are eligible.
        // Guarded to POST only so an empty GET request doesn't wipe the existing value before
        // rendering it.
        if ($request->isMethod('POST')) {
            $skillGroup->setTeacher($this->resolveProgramTeacher($program, $request->request->get('teacher')));
        }

        $form = $this->createForm(SkillGroupType::class, $skillGroup, ['optionChoices' => $program->getOptions()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipSkillGroupUpdatedFlashMessage' : 'internshipSkillGroupCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_skill_groups', ['id' => $program->getId()]);
        }

        return $this->render('program/skill_group_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    // Backs the teacher ajax tom-select field in skill_group_new.html.twig - only the program's
    // own teachers are eligible, same convention as refereesSearch()/
    // ProgramTimetableSettingsController::teachersSearch().
    #[Route(path: '/programs/{id}/settings/skill-groups/teachers-search', name: 'app_program_settings_skill_groups_teachers_search')]
    public function skillGroupTeachersSearch(int $id, Request $request, ProgramRepository $repository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $limit = 20;
        $query = mb_strtolower((string) $request->query->get('q', ''));

        $candidates = array_values(array_filter(
            $program->getTeachers()->toArray(),
            static fn (User $user): bool => '' === $query || str_contains(mb_strtolower($user->getDisplayName() ?? $user->getUsername()), $query),
        ));

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], \array_slice($candidates, 0, $limit)),
            'pagination' => ['more' => \count($candidates) > $limit],
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/deactivate', name: 'app_program_settings_skill_groups_deactivate', methods: ['POST'])]
    public function deactivateSkillGroup(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $skillGroup->setInactiveDate(new \DateTimeImmutable());
        $skillGroup->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/data', name: 'app_program_settings_skill_groups_data')]
    public function skillGroupsData(int $id, Request $request, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readActiveFilterableDataTableParams($request);

        $total = $skillGroupRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $skillGroupRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $skillGroupRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (SkillGroup $skillGroup): array => [
                    'id' => $skillGroup->getId(),
                    'isInactive' => null !== $skillGroup->getInactiveDate(),
                    // Rendered as trusted HTML by the 'html' render keyword on this column
                    // (see _skill_groups_content.html.twig) - the default column render escapes it.
                    'label' => sprintf(
                        '<a href="%s">%s</a>',
                        htmlspecialchars($this->generateUrl('app_program_settings_skill_groups_skills', ['id' => $program->getId(), 'groupId' => $skillGroup->getId()])),
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

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills', name: 'app_program_settings_skill_groups_skills')]
    public function skillsList(int $id, int $groupId, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);

        return $this->render('program/skill_group_skills.html.twig', [
            'program' => $program,
            'skillGroup' => $skillGroup,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/new', name: 'app_program_settings_skill_groups_skills_new')]
    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/{skillId}/edit', name: 'app_program_settings_skill_groups_skills_edit')]
    public function skillForm(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository, ?int $skillId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
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

            return $this->redirectToRoute('app_program_settings_skill_groups_skills', ['id' => $program->getId(), 'groupId' => $skillGroup->getId()]);
        }

        return $this->render('program/skill_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
            'skillGroup' => $skillGroup,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/{skillId}/deactivate', name: 'app_program_settings_skill_groups_skills_deactivate', methods: ['POST'])]
    public function deactivateSkill(int $id, int $groupId, int $skillId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $skill = $this->findSkillOrNotFound($skillRepository, $skillGroup, $skillId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $skill->setInactiveDate(new \DateTimeImmutable());
        $skill->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/data', name: 'app_program_settings_skill_groups_skills_data')]
    public function skillsData(int $id, int $groupId, Request $request, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        [$draw, $start, $length, $search, $includeInactive] = $this->readActiveFilterableDataTableParams($request);

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

    private function findSkillGroupOrNotFound(SkillGroupRepository $repository, Program $program, int $groupId): SkillGroup
    {
        $skillGroup = $repository->find($groupId) ?? throw $this->createNotFoundException();

        if ($skillGroup->getProgram()->getId() !== $program->getId()) {
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
}
