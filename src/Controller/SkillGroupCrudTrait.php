<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Program;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\User;
use App\Form\SkillGroupType;
use App\Form\SkillType;
use App\Repository\SkillGroupRepository;
use App\Repository\SkillRepository;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The « Groupes de compétences » screens, written once and reached from two menus.
 *
 * Formation > Paramétrage carries them for whoever administers a formation; UFA > Formations
 * carries the same thing under the name « Compétences », because the UFA team maintains the
 * référentiel of its own formations and has no business in the rest of the settings sheet.
 *
 * **The bodies live here and the routes do not.** A trait cannot carry `#[Route]` without both host
 * classes declaring the same route names, and the two doors genuinely differ on three points, all
 * of them declared by the host:
 *
 * - the feature that opens them (`tsf_referential` on one side, `ufa_booklet` on the other), which
 *   is the whole reason there are two doors rather than one;
 * - the shell the list renders inside - the Paramétrage tab strip or the UFA one;
 * - the route names every link, redirect and form action points at, so that a reader who came in
 *   through UFA stays in UFA.
 *
 * Everything else - the drag-and-drop reordering, the two forms, the ajax teacher search, the soft
 * deletes, the HTML sanitising - is the same code running twice.
 */
trait SkillGroupCrudTrait
{
    /**
     * The route names this door uses, keyed as the templates read them. See
     * App\Controller\Program\SettingsSkillGroupController::skillRoutes() for the canonical set.
     *
     * @return array<string, string>
     */
    abstract protected function skillRoutes(): array;

    /**
     * Renders the group list inside this door's own shell.
     *
     * @param list<SkillGroup> $skillGroups
     */
    abstract protected function renderSkillGroupList(Program $program, array $skillGroups): Response;

    /** Resolves the formation, or 404s - each host reads it from its own repository. */
    abstract protected function skillProgram(int $id): Program;

    /**
     * The segments the sub-screens draw between « Accueil » and their own title, so that a reader
     * who came in through UFA is not sent back into Paramétrage by the trail.
     *
     * @return list<array{labelKey: string|null, label: string|null, url: string}>
     */
    abstract protected function skillBreadcrumb(Program $program): array;

    protected function doSkillGroupsList(int $id, SkillGroupRepository $skillGroupRepository): Response
    {
        // Rendered server-side, every row at once, rather than through a DataTables endpoint: the
        // list reorders by drag-and-drop, which needs the whole list in the DOM and must not be
        // bound inside a subtree DataTables rewraps. A program holds a handful of blocks, so there
        // is nothing to page.
        $program = $this->skillProgram($id);

        return $this->renderSkillGroupList($program, $skillGroupRepository->findAllOrderedForProgram($program, true));
    }

    // Same "re-fetch canonical order, apply new positions" shape as
    // UfaConfigurationController::reorderBehaviorCriteria().
    protected function doReorderSkillGroups(int $id, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        $program = $this->skillProgram($id);
        $this->assertSkillToken('program_settings_skill_groups_reorder', $request);

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

    protected function doSkillGroupForm(int $id, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, ?int $groupId): Response
    {
        $program = $this->skillProgram($id);
        $isEdit = null !== $groupId;
        // A real SkillGroup backs the "new" form too, not null - visibleInBooklet/visibleInProgram
        // are ordinary mapped checkboxes that read their initial view state straight off the model,
        // so only a real instance (picking up the `= true` property defaults) renders them
        // pre-checked.
        $skillGroup = $isEdit ? $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId) : new SkillGroup('', $program);

        // Optional "teacher" is picked via an ajax tom-select field embedded directly in the
        // template (not a mapped SkillGroupType field) and resolved here - only the program's own
        // teachers are eligible. Guarded to POST only so an empty GET request doesn't wipe the
        // existing value before rendering it.
        if ($request->isMethod('POST')) {
            $skillGroup->setTeacher($this->resolveSkillTeacher($program, $request->request->get('teacher')));
        }

        $form = $this->createForm(SkillGroupType::class, $skillGroup, ['optionChoices' => $program->getOptions()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampSkillAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipSkillGroupUpdatedFlashMessage' : 'internshipSkillGroupCreatedFlashMessage');

            return $this->redirectToRoute($this->skillRoutes()['list'], ['id' => $program->getId()]);
        }

        return $this->render('program/skill_group_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
            'skillRoutes' => $this->skillRoutes(),
            'skillBreadcrumb' => $this->skillBreadcrumb($program),
        ]);
    }

    // Backs the teacher ajax tom-select field - only the program's own teachers are eligible, same
    // convention as ProgramTimetableSettingsController::teachersSearch().
    protected function doSkillGroupTeachersSearch(int $id, Request $request): JsonResponse
    {
        $program = $this->skillProgram($id);
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

    protected function doDeactivateSkillGroup(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        $program = $this->skillProgram($id);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $this->assertSkillToken('program_settings_deactivate', $request);

        $skillGroup->setInactiveDate(new \DateTimeImmutable());
        $skillGroup->setInactivatedBy($this->currentSkillUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    protected function doSkillsList(int $id, int $groupId, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): Response
    {
        $program = $this->skillProgram($id);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);

        return $this->render('program/skill_group_skills.html.twig', [
            'program' => $program,
            'skillGroup' => $skillGroup,
            'skills' => $skillRepository->findAllOrderedForSkillGroup($skillGroup, true),
            'skillRoutes' => $this->skillRoutes(),
            'skillBreadcrumb' => $this->skillBreadcrumb($program),
        ]);
    }

    protected function doReorderSkills(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): JsonResponse
    {
        $program = $this->skillProgram($id);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $this->assertSkillToken('program_settings_skills_reorder', $request);

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

    protected function doSkillForm(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository, HtmlSanitizerInterface $sanitizer, ?int $skillId): Response
    {
        $program = $this->skillProgram($id);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $isEdit = null !== $skillId;
        $skill = $isEdit ? $this->findSkillOrNotFound($skillRepository, $skillGroup, $skillId) : new Skill('', $skillGroup);

        // "Intervenant" is picked via the same ajax tom-select as the group's own teacher (and the
        // same search endpoint), resolved here rather than mapped. POST only, so rendering the form
        // doesn't wipe the stored value.
        if ($request->isMethod('POST')) {
            $skill->setTeacher($this->resolveSkillTeacher($program, $request->request->get('teacher')));
        }

        $form = $this->createForm(SkillType::class, $skill);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            // HugeRTE-authored HTML, sanitized the same way as the Livret's own rich text - the
            // referential export prints it raw.
            $skill
                ->setKnowledgeHtml($this->sanitizeSkillHtml($sanitizer, $skill->getKnowledgeHtml()))
                ->setActivitiesHtml($this->sanitizeSkillHtml($sanitizer, $skill->getActivitiesHtml()))
                ->setPerformanceCriteriaHtml($this->sanitizeSkillHtml($sanitizer, $skill->getPerformanceCriteriaHtml()))
            ;
            $this->stampSkillAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'skillUpdatedFlashMessage' : 'skillCreatedFlashMessage');

            return $this->redirectToRoute($this->skillRoutes()['skills'], ['id' => $program->getId(), 'groupId' => $skillGroup->getId()]);
        }

        return $this->render('program/skill_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
            'skillGroup' => $skillGroup,
            'skillRoutes' => $this->skillRoutes(),
            'skillBreadcrumb' => $this->skillBreadcrumb($program),
        ]);
    }

    protected function doDeactivateSkill(int $id, int $groupId, int $skillId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): JsonResponse
    {
        $program = $this->skillProgram($id);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $skill = $this->findSkillOrNotFound($skillRepository, $skillGroup, $skillId);
        $this->assertSkillToken('program_settings_deactivate', $request);

        $skill->setInactiveDate(new \DateTimeImmutable());
        $skill->setInactivatedBy($this->currentSkillUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    /** Empty stays empty: a sanitizer given '' returns '', and null must not become one. */
    private function sanitizeSkillHtml(HtmlSanitizerInterface $sanitizer, ?string $html): ?string
    {
        return null !== $html && '' !== $html ? $sanitizer->sanitize($html) : $html;
    }

    private function resolveSkillTeacher(Program $program, mixed $teacherId): ?User
    {
        if (!\is_string($teacherId) && !\is_int($teacherId)) {
            return null;
        }

        $id = (int) $teacherId;

        if (0 === $id) {
            return null;
        }

        foreach ($program->getTeachers() as $teacher) {
            if ($teacher->getId() === $id) {
                return $teacher;
            }
        }

        return null;
    }

    private function stampSkillAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentSkillUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentSkillUser());
        }
    }

    private function assertSkillToken(string $tokenId, Request $request): void
    {
        // Both shapes reach these endpoints: the drag-and-drop and the soft deletes are fetch calls
        // carrying the token in a header, the forms carry it in the body.
        $token = $request->headers->get('X-CSRF-TOKEN') ?? $request->request->get('_token');

        if (!$this->isCsrfTokenValid($tokenId, \is_string($token) ? $token : null)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function currentSkillUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
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
