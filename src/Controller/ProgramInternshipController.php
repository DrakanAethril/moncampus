<?php

namespace App\Controller;

use App\Entity\InternshipOptionExamModality;
use App\Entity\InternshipProgramInfo;
use App\Entity\InternshipSkillCriterion;
use App\Entity\InternshipSkillGroup;
use App\Entity\InternshipTeamEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Period;
use App\Entity\Program;
use App\Entity\User;
use App\Form\InternshipProgramInfoType;
use App\Form\InternshipSkillCriterionType;
use App\Form\InternshipSkillGroupType;
use App\Form\InternshipTeamEvaluationType;
use App\Form\InternshipTutorLinkType;
use App\Form\ProgramInfoUploadType;
use App\Repository\InternshipOptionExamModalityRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\InternshipSkillCriterionRepository;
use App\Repository\InternshipSkillGroupRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\PeriodRepository;
use App\Repository\ProgramRepository;
use App\Repository\TopicRepository;
use App\Service\FileUploadService;
use App\Service\GotenbergUnavailableException;
use App\Service\InternshipBookletBuilder;
use App\Service\InternshipBookletPdfExporter;
use App\Service\InternshipTutorProvisioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The per-program "Livret Alternant" area, reached via its own sibling entry in the program's
// dropend submenu (next to Réglages/Exports/Reporting) - kept separate from
// ProgramSettingsController rather than one more tab in templates/program/settings.html.twig,
// which is already dense with 7 tabs.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ProgramInternshipController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    private const string PROGRAM_INFO_UPLOAD_PREFIX = 'internship-program-info/';

    #[Route(path: '/programs/{id}/internship', name: 'app_program_internship')]
    #[Route(path: '/programs/{id}/internship/team', name: 'app_program_internship_team')]
    public function teamTab(int $id, ProgramRepository $repository, TopicRepository $topicRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        // Read-only: the teaching team roster is derived from the program's existing Topics
        // (discipline -> teacher) rather than a separate entity - see the entity docblocks for
        // why this isn't duplicated here.
        $topicsByTeacher = [];
        foreach ($topicRepository->findAllActiveForProgram($program) as $topic) {
            $teacher = $topic->getTeacher();
            $key = $teacher?->getId() ?? 0;

            if (!isset($topicsByTeacher[$key])) {
                $topicsByTeacher[$key] = ['teacher' => $teacher, 'topics' => []];
            }

            $topicsByTeacher[$key]['topics'][] = $topic;
        }

        return $this->render('program/internship.html.twig', [
            'program' => $program,
            'activeTab' => 'team',
            'topicsByTeacher' => $topicsByTeacher,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/skills', name: 'app_program_internship_skills')]
    public function skillsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'skills');
    }

    #[Route(path: '/programs/{id}/internship/tutors', name: 'app_program_internship_tutors')]
    public function tutorsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'tutors');
    }

    #[Route(path: '/programs/{id}/internship/info', name: 'app_program_internship_info')]
    public function infoTab(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, InternshipOptionExamModalityRepository $examModalityRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $info = $infoRepository->findOneByProgram($program);
        $isNew = null === $info;

        if ($isNew) {
            $info = new InternshipProgramInfo($program);
        }

        $form = $this->createForm(InternshipProgramInfoType::class, $info);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->stampAuditFields($info, !$isNew);

            $entityManager->persist($info);
            $entityManager->flush();

            $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_info', ['id' => $program->getId()]);
        }

        return $this->render('program/internship.html.twig', [
            'program' => $program,
            'activeTab' => 'info',
            'form' => $form,
            'info' => $info,
            'coverUploadForm' => $this->createForm(ProgramInfoUploadType::class, null, ['fieldLabel' => 'programInfoCoverUploadFieldLabel'])->createView(),
            'calendarUploadForm' => $this->createForm(ProgramInfoUploadType::class, null, ['fieldLabel' => 'programInfoCalendarUploadFieldLabel'])->createView(),
            'examModalitiesByOptionId' => $examModalityRepository->findMapForProgram($program),
        ]);
    }

    #[Route(path: '/programs/{id}/internship/info/exam-modalities', name: 'app_program_internship_info_exam_modalities', methods: ['POST'])]
    public function updateOptionExamModalities(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipOptionExamModalityRepository $examModalityRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        if (!$this->isCsrfTokenValid('program_internship_exam_modalities', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $submittedTexts = $request->request->all('examModalities');

        foreach ($program->getOptions() as $option) {
            $raw = trim((string) ($submittedTexts[$option->getId()] ?? ''));
            $existingOverride = $examModalityRepository->findOneForProgramAndOption($program, $option);

            if ('' === $raw) {
                if (null !== $existingOverride) {
                    $entityManager->remove($existingOverride);
                }

                continue;
            }

            if (null !== $existingOverride) {
                $existingOverride->setExamModalityText($raw);
            } else {
                $entityManager->persist(new InternshipOptionExamModality($program, $option, $raw));
            }
        }

        $entityManager->flush();
        $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

        return $this->redirectToRoute('app_program_internship_info', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/internship/info/cover', name: 'app_program_internship_info_cover_upload', methods: ['POST'])]
    public function uploadCoverPage(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, FileUploadService $fileUploadService): Response
    {
        return $this->handleProgramInfoUpload(
            $id, $request, $entityManager, $repository, $infoRepository, $fileUploadService,
            'cover',
            static fn (InternshipProgramInfo $info): ?string => $info->getCoverPageKey(),
            static function (InternshipProgramInfo $info, ?string $key): void { $info->setCoverPageKey($key); },
            'programInfoCoverUploadFieldLabel', 'programInfoCoverUploadedFlashMessage',
        );
    }

    #[Route(path: '/programs/{id}/internship/info/cover/delete', name: 'app_program_internship_info_cover_delete', methods: ['POST'])]
    public function deleteCoverPage(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, FileUploadService $fileUploadService): Response
    {
        return $this->handleProgramInfoDelete(
            $id, $request, $entityManager, $repository, $infoRepository, $fileUploadService,
            static fn (InternshipProgramInfo $info): ?string => $info->getCoverPageKey(),
            static function (InternshipProgramInfo $info, ?string $key): void { $info->setCoverPageKey($key); },
            'programInfoCoverDeletedFlashMessage',
        );
    }

    #[Route(path: '/programs/{id}/internship/info/calendar', name: 'app_program_internship_info_calendar_upload', methods: ['POST'])]
    public function uploadCalendar(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, FileUploadService $fileUploadService): Response
    {
        return $this->handleProgramInfoUpload(
            $id, $request, $entityManager, $repository, $infoRepository, $fileUploadService,
            'calendar',
            static fn (InternshipProgramInfo $info): ?string => $info->getCalendarKey(),
            static function (InternshipProgramInfo $info, ?string $key): void { $info->setCalendarKey($key); },
            'programInfoCalendarUploadFieldLabel', 'programInfoCalendarUploadedFlashMessage',
        );
    }

    #[Route(path: '/programs/{id}/internship/info/calendar/delete', name: 'app_program_internship_info_calendar_delete', methods: ['POST'])]
    public function deleteCalendar(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, FileUploadService $fileUploadService): Response
    {
        return $this->handleProgramInfoDelete(
            $id, $request, $entityManager, $repository, $infoRepository, $fileUploadService,
            static fn (InternshipProgramInfo $info): ?string => $info->getCalendarKey(),
            static function (InternshipProgramInfo $info, ?string $key): void { $info->setCalendarKey($key); },
            'programInfoCalendarDeletedFlashMessage',
        );
    }

    /**
     * @param \Closure(InternshipProgramInfo): ?string          $getKey
     * @param \Closure(InternshipProgramInfo, ?string): mixed   $setKey
     */
    private function handleProgramInfoUpload(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, FileUploadService $fileUploadService, string $slot, \Closure $getKey, \Closure $setKey, string $fieldLabel, string $successFlash): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $info = $infoRepository->findOneByProgram($program);
        $isNew = null === $info;

        if ($isNew) {
            $info = new InternshipProgramInfo($program);
        }

        $form = $this->createForm(ProgramInfoUploadType::class, null, ['fieldLabel' => $fieldLabel]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();
            $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();

            // Old object is only deleted after the new one is safely persisted, same reasoning as
            // ProfileController::uploadAvatar() - a mid-upload failure never leaves a broken key.
            $oldKey = $getKey($info);
            $newKey = $fileUploadService->upload(
                self::PROGRAM_INFO_UPLOAD_PREFIX,
                sprintf('%d-%s-%d.%s', $program->getId(), $slot, time(), $extension),
                $file,
            );

            $setKey($info, $newKey);
            $this->stampAuditFields($info, !$isNew);

            $entityManager->persist($info);
            $entityManager->flush();

            if (null !== $oldKey) {
                $fileUploadService->delete($oldKey);
            }

            $this->addFlash('success', $successFlash);
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_program_internship_info', ['id' => $program->getId()]);
    }

    /**
     * @param \Closure(InternshipProgramInfo): ?string        $getKey
     * @param \Closure(InternshipProgramInfo, ?string): mixed $setKey
     */
    private function handleProgramInfoDelete(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, FileUploadService $fileUploadService, \Closure $getKey, \Closure $setKey, string $successFlash): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $info = $infoRepository->findOneByProgram($program);

        if (!$this->isCsrfTokenValid('program_internship_info_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $key = null !== $info ? $getKey($info) : null;

        if (null !== $info && null !== $key) {
            $setKey($info, null);
            $entityManager->flush();
            $fileUploadService->delete($key);

            $this->addFlash('success', $successFlash);
        }

        return $this->redirectToRoute('app_program_internship_info', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/internship/skills/new', name: 'app_program_internship_skills_new')]
    #[Route(path: '/programs/{id}/internship/skills/{groupId}/edit', name: 'app_program_internship_skills_edit')]
    public function skillGroupForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipSkillGroupRepository $skillGroupRepository, ?int $groupId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = null !== $groupId ? $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId) : null;
        $isEdit = null !== $skillGroup;

        $form = $this->createForm(InternshipSkillGroupType::class, $skillGroup, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipSkillGroupUpdatedFlashMessage' : 'internshipSkillGroupCreatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_skills', ['id' => $program->getId()]);
        }

        return $this->render('program/internship_skill_group_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/skills/{groupId}/deactivate', name: 'app_program_internship_skills_deactivate', methods: ['POST'])]
    public function deactivateSkillGroup(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipSkillGroupRepository $skillGroupRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $this->assertValidToken('program_internship_deactivate', $request);

        $skillGroup->setInactiveDate(new \DateTimeImmutable());
        $skillGroup->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/internship/skills/data', name: 'app_program_internship_skills_data')]
    public function skillGroupsData(int $id, Request $request, ProgramRepository $repository, InternshipSkillGroupRepository $skillGroupRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $skillGroupRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $skillGroupRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $skillGroupRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (InternshipSkillGroup $skillGroup): array => [
                    'id' => $skillGroup->getId(),
                    'isInactive' => null !== $skillGroup->getInactiveDate(),
                    // Rendered as trusted HTML by the 'html' render keyword on this column
                    // (see _skills_content.html.twig) - the default column render escapes it.
                    'label' => sprintf(
                        '<a href="%s">%s</a>',
                        htmlspecialchars($this->generateUrl('app_program_internship_skill_criteria', ['id' => $program->getId(), 'groupId' => $skillGroup->getId()])),
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

    #[Route(path: '/programs/{id}/internship/skills/{groupId}/criteria', name: 'app_program_internship_skill_criteria')]
    public function skillCriteriaList(int $id, int $groupId, ProgramRepository $repository, InternshipSkillGroupRepository $skillGroupRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);

        return $this->render('program/internship_skill_criteria.html.twig', [
            'program' => $program,
            'skillGroup' => $skillGroup,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/skills/{groupId}/criteria/new', name: 'app_program_internship_skill_criteria_new')]
    #[Route(path: '/programs/{id}/internship/skills/{groupId}/criteria/{criterionId}/edit', name: 'app_program_internship_skill_criteria_edit')]
    public function skillCriterionForm(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipSkillGroupRepository $skillGroupRepository, InternshipSkillCriterionRepository $criterionRepository, ?int $criterionId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $criterion = null !== $criterionId ? $this->findSkillCriterionOrNotFound($criterionRepository, $skillGroup, $criterionId) : null;
        $isEdit = null !== $criterion;

        $form = $this->createForm(InternshipSkillCriterionType::class, $criterion, ['skillGroup' => $skillGroup]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipSkillCriterionUpdatedFlashMessage' : 'internshipSkillCriterionCreatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_skill_criteria', ['id' => $program->getId(), 'groupId' => $skillGroup->getId()]);
        }

        return $this->render('program/internship_skill_criterion_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
            'skillGroup' => $skillGroup,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/skills/{groupId}/criteria/{criterionId}/deactivate', name: 'app_program_internship_skill_criteria_deactivate', methods: ['POST'])]
    public function deactivateSkillCriterion(int $id, int $groupId, int $criterionId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipSkillGroupRepository $skillGroupRepository, InternshipSkillCriterionRepository $criterionRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $criterion = $this->findSkillCriterionOrNotFound($criterionRepository, $skillGroup, $criterionId);
        $this->assertValidToken('program_internship_deactivate', $request);

        $criterion->setInactiveDate(new \DateTimeImmutable());
        $criterion->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/internship/skills/{groupId}/criteria/data', name: 'app_program_internship_skill_criteria_data')]
    public function skillCriteriaData(int $id, int $groupId, Request $request, ProgramRepository $repository, InternshipSkillGroupRepository $skillGroupRepository, InternshipSkillCriterionRepository $criterionRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $criterionRepository->countAllForSkillGroup($skillGroup, null, $includeInactive);
        $filteredTotal = '' !== $search ? $criterionRepository->countAllForSkillGroup($skillGroup, $search, $includeInactive) : $total;
        $rows = $criterionRepository->findPageForSkillGroupOrderedByMostRecent($skillGroup, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (InternshipSkillCriterion $criterion): array => [
                    'id' => $criterion->getId(),
                    'isInactive' => null !== $criterion->getInactiveDate(),
                    'label' => $criterion->getLabel(),
                    'creationDate' => $criterion->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $criterion->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($criterion->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($criterion->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($criterion->getLastUpdatedBy()),
                    'lastUpdatedDate' => $criterion->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/programs/{id}/internship/tutors/new', name: 'app_program_internship_tutors_new')]
    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/edit', name: 'app_program_internship_tutors_edit')]
    public function tutorLinkForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipTutorProvisioningService $provisioningService, ?int $tutorLinkId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = null !== $tutorLinkId ? $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId) : new InternshipTutorLink($program);
        $isEdit = null !== $tutorLinkId;

        $form = $this->createForm(InternshipTutorLinkType::class, $tutorLink, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // A tutor already resolved from a prior login (or a prior edit of this same link) is
            // left untouched - only an unresolved tutor needs (re)provisioning, and provisioning
            // itself is a no-op DB insert, never a wait.
            if (null === $tutorLink->getTutor()) {
                $provisioningService->provision($tutorLink, $this->currentUser());
            }

            $this->stampAuditFields($tutorLink, $isEdit);

            $entityManager->persist($tutorLink);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipTutorLinkUpdatedFlashMessage' : 'internshipTutorLinkCreatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_tutors', ['id' => $program->getId()]);
        }

        return $this->render('program/internship_tutor_link_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/deactivate', name: 'app_program_internship_tutors_deactivate', methods: ['POST'])]
    public function deactivateTutorLink(int $id, int $tutorLinkId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);
        $this->assertValidToken('program_internship_deactivate', $request);

        $tutorLink->setInactiveDate(new \DateTimeImmutable());
        $tutorLink->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/internship/tutors/data', name: 'app_program_internship_tutors_data')]
    public function tutorLinksData(int $id, Request $request, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $tutorLinkRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $tutorLinkRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $tutorLinkRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (InternshipTutorLink $tutorLink): array => [
                    'id' => $tutorLink->getId(),
                    'isInactive' => null !== $tutorLink->getInactiveDate(),
                    // Doubles as the entry point to this student's pedagogical-team remarks -
                    // rendered as trusted HTML by the 'html' render keyword on this column (see
                    // _tutors_content.html.twig), same technique as skillGroupsData()'s 'label'.
                    'studentName' => sprintf(
                        '<a href="%s">%s</a>',
                        htmlspecialchars($this->generateUrl('app_program_internship_tutors_team_evaluations', ['id' => $program->getId(), 'tutorLinkId' => $tutorLink->getId()])),
                        htmlspecialchars($this->userLabel($tutorLink->getStudent())),
                    ),
                    'tutorName' => trim($tutorLink->getTutorFirstName().' '.$tutorLink->getTutorLastName()),
                    'enterpriseName' => $tutorLink->getEnterprise()?->getName(),
                    'contractStartDate' => $tutorLink->getContractStartDate()?->format('d/m/Y') ?? '—',
                    'contractEndDate' => $tutorLink->getContractEndDate()?->format('d/m/Y') ?? '—',
                    'creationDate' => $tutorLink->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $tutorLink->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($tutorLink->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($tutorLink->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($tutorLink->getLastUpdatedBy()),
                    'lastUpdatedDate' => $tutorLink->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/booklet', name: 'app_program_internship_tutors_booklet')]
    public function tutorLinkBooklet(int $id, int $tutorLinkId, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletBuilder $bookletBuilder): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);

        return $this->render('internship/booklet.html.twig', $bookletBuilder->build($tutorLink));
    }

    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/booklet/pdf', name: 'app_program_internship_tutors_booklet_pdf')]
    public function tutorLinkBookletPdf(int $id, int $tutorLinkId, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletPdfExporter $exporter): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);

        // Redirects back to the tutors list (not the booklet "View" route) on failure -
        // internship/booklet.html.twig extends base.html.twig directly with no flash-message
        // region, so an error flash set there would never actually be shown to the user.
        return $this->exportBookletPdf($tutorLink, 'app_program_internship_tutors', ['id' => $program->getId()], $exporter);
    }

    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/team-evaluations', name: 'app_program_internship_tutors_team_evaluations')]
    public function tutorLinkTeamEvaluations(int $id, int $tutorLinkId, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, PeriodRepository $periodRepository, InternshipTeamEvaluationRepository $teamEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);

        $evaluationsByPeriodId = [];
        foreach ($teamEvaluationRepository->findAllForStudentAndProgram($tutorLink->getStudent(), $program) as $evaluation) {
            $evaluationsByPeriodId[$evaluation->getPeriod()->getId()] = $evaluation;
        }

        $rows = array_map(
            static fn (Period $period): array => [
                'period' => $period,
                'submitted' => isset($evaluationsByPeriodId[$period->getId()]),
            ],
            $periodRepository->findAllActive(),
        );

        return $this->render('program/internship_tutor_team_evaluations.html.twig', [
            'program' => $program,
            'tutorLink' => $tutorLink,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/team-evaluations/{periodId}', name: 'app_program_internship_tutors_team_evaluation', requirements: ['periodId' => '\d+'])]
    public function tutorLinkTeamEvaluation(int $id, int $tutorLinkId, int $periodId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, PeriodRepository $periodRepository, InternshipTeamEvaluationRepository $teamEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);
        $period = $periodRepository->find($periodId) ?? throw $this->createNotFoundException();

        $evaluation = $teamEvaluationRepository->findOneForStudentAndPeriod($tutorLink->getStudent(), $period);
        $isEdit = null !== $evaluation;

        if (!$isEdit) {
            $evaluation = new InternshipTeamEvaluation($tutorLink->getStudent(), $program, $period);
        }

        $form = $this->createForm(InternshipTeamEvaluationType::class, $evaluation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setValidationDate(new \DateTimeImmutable());
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'internshipTeamEvaluationSavedFlashMessage');

            return $this->redirectToRoute('app_program_internship_tutors_team_evaluations', ['id' => $program->getId(), 'tutorLinkId' => $tutorLink->getId()]);
        }

        return $this->render('program/internship_tutor_team_evaluation.html.twig', [
            'form' => $form,
            'program' => $program,
            'tutorLink' => $tutorLink,
            'period' => $period,
        ]);
    }

    private function renderTab(int $id, ProgramRepository $repository, string $tab): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/internship.html.twig', [
            'program' => $program,
            'activeTab' => $tab,
        ]);
    }

    private function findSkillGroupOrNotFound(InternshipSkillGroupRepository $repository, Program $program, int $groupId): InternshipSkillGroup
    {
        $skillGroup = $repository->find($groupId) ?? throw $this->createNotFoundException();

        if ($skillGroup->getProgram()?->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $skillGroup;
    }

    private function findSkillCriterionOrNotFound(InternshipSkillCriterionRepository $repository, InternshipSkillGroup $skillGroup, int $criterionId): InternshipSkillCriterion
    {
        $criterion = $repository->find($criterionId) ?? throw $this->createNotFoundException();

        if ($criterion->getSkillGroup()?->getId() !== $skillGroup->getId()) {
            throw $this->createNotFoundException();
        }

        return $criterion;
    }

    /** @param array<string, mixed> $backRouteParams */
    private function exportBookletPdf(InternshipTutorLink $tutorLink, string $backRoute, array $backRouteParams, InternshipBookletPdfExporter $exporter): Response
    {
        try {
            $pdf = $exporter->export($tutorLink, $this->renderView(...));
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'internshipBookletPdfExportFailedFlashMessage');

            return $this->redirectToRoute($backRoute, $backRouteParams);
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('livret-alternant-%s.pdf', $tutorLink->getStudent()->getUsername())),
        ]);
    }

    private function findTutorLinkOrNotFound(InternshipTutorLinkRepository $repository, Program $program, int $tutorLinkId): InternshipTutorLink
    {
        $tutorLink = $repository->find($tutorLinkId) ?? throw $this->createNotFoundException();

        if ($tutorLink->getProgram()?->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $tutorLink;
    }

    private function findOrNotFound(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->assertProgramFeatureEnabled($program->isInternshipManagementEnabled());

        return $program;
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

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }

    private function assertValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
