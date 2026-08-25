<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\SkillGroupType;
use App\Form\SkillType;
use App\Repository\ProgramRepository;
use App\Repository\SkillGroupRepository;
use App\Repository\SkillRepository;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
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
#[RequiresFeature(Feature::TsfReferential)]
class SettingsSkillGroupController extends AbstractController
{
    use ProgramSettingsTabTrait;

    #[Route(path: '/programs/{id}/settings/skill-groups', name: 'app_program_settings_skill_groups')]
    public function skillGroupsTab(int $id, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository): Response
    {
        // Rendered server-side, every row at once, rather than through the DataTables endpoint this
        // replaces: the list reorders by drag-and-drop, which needs the whole list in the DOM and
        // must not be bound inside a subtree DataTables rewraps. A program holds a handful of
        // blocks, so there is nothing to page.
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/settings.html.twig', [
            'program' => $program,
            'activeTab' => 'skill_groups',
            'skillGroups' => $skillGroupRepository->findAllOrderedForProgram($program, true),
        ]);
    }

    // Same "re-fetch canonical order, apply new positions" shape as
    // UfaConfigurationController::reorderBehaviorCriteria().
    #[Route(path: '/programs/{id}/settings/skill-groups/reorder', name: 'app_program_settings_skill_groups_reorder', methods: ['POST'])]
    public function reorderSkillGroups(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertValidToken('program_settings_skill_groups_reorder', $request);

        $groupsById = [];
        foreach ($skillGroupRepository->findAllOrderedForProgram($program, true) as $skillGroup) {
            $groupsById[$skillGroup->getId()] = $skillGroup;
        }

        foreach (JsonRequestPayload::fromRequest($request)->ids() as $position => $groupId) {
            ($groupsById[$groupId] ?? null)?->setOrder($position);
        }

        $entityManager->flush();

        return $this->json(['success' => true]);
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

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills', name: 'app_program_settings_skill_groups_skills')]
    public function skillsList(int $id, int $groupId, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);

        return $this->render('program/skill_group_skills.html.twig', [
            'program' => $program,
            'skillGroup' => $skillGroup,
            'skills' => $skillRepository->findAllOrderedForSkillGroup($skillGroup, true),
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/reorder', name: 'app_program_settings_skill_groups_skills_reorder', methods: ['POST'])]
    public function reorderSkills(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $this->assertValidToken('program_settings_skills_reorder', $request);

        $skillsById = [];
        foreach ($skillRepository->findAllOrderedForSkillGroup($skillGroup, true) as $skill) {
            $skillsById[$skill->getId()] = $skill;
        }

        foreach (JsonRequestPayload::fromRequest($request)->ids() as $position => $skillId) {
            ($skillsById[$skillId] ?? null)?->setOrder($position);
        }

        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/new', name: 'app_program_settings_skill_groups_skills_new')]
    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/{skillId}/edit', name: 'app_program_settings_skill_groups_skills_edit')]
    public function skillForm(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer, ?int $skillId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $isEdit = null !== $skillId;
        $skill = $isEdit ? $this->findSkillOrNotFound($skillRepository, $skillGroup, $skillId) : new Skill('', $skillGroup);

        // "Intervenant" is picked via the same ajax tom-select as the group's own teacher (and the
        // same search endpoint), resolved here rather than mapped - see skillGroupForm(). POST only,
        // so rendering the form doesn't wipe the stored value.
        if ($request->isMethod('POST')) {
            $skill->setTeacher($this->resolveProgramTeacher($program, $request->request->get('teacher')));
        }

        $form = $this->createForm(SkillType::class, $skill);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            // HugeRTE-authored HTML, sanitized the same way as the Livret's own rich text
            // (InternshipExamModalityController) - the referential export prints it raw.
            $skill
                ->setKnowledgeHtml($this->sanitizeOrNull($sanitizer, $skill->getKnowledgeHtml()))
                ->setActivitiesHtml($this->sanitizeOrNull($sanitizer, $skill->getActivitiesHtml()))
                ->setPerformanceCriteriaHtml($this->sanitizeOrNull($sanitizer, $skill->getPerformanceCriteriaHtml()))
            ;
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

    /** Empty stays empty: a sanitizer given '' returns '', and null must not become one. */
    private function sanitizeOrNull(HtmlSanitizerInterface $sanitizer, ?string $html): ?string
    {
        return null !== $html && '' !== $html ? $sanitizer->sanitize($html) : $html;
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
