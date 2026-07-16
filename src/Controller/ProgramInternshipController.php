<?php

namespace App\Controller;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipOptionExamModality;
use App\Entity\InternshipOptionLegalName;
use App\Entity\InternshipProgramInfo;
use App\Entity\InternshipStudentEvaluation;
use App\Entity\InternshipTeamEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Form\InternshipContractModalitiesType;
use App\Form\InternshipEvaluationPeriodType;
use App\Form\InternshipExamModalityType;
use App\Form\InternshipLegalNameType;
use App\Form\InternshipStudentEvaluationType;
use App\Form\InternshipTeamEvaluationType;
use App\Form\InternshipTutorEvaluationType;
use App\Form\InternshipTutorLinkType;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipOptionExamModalityRepository;
use App\Repository\InternshipOptionLegalNameRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SkillLevelRepository;
use App\Service\GotenbergUnavailableException;
use App\Service\InternshipBookletBuilder;
use App\Service\InternshipBookletPdfExporter;
use App\Service\InternshipTutorEvaluationBuilder;
use App\Service\InternshipTutorProvisioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

// The per-program "Livret de l'alternant" area, one of the 3 groups the "Paramétrage" dropend
// splits into (alongside ProgramSettingsController's "Programme" and
// ProgramTimetableSettingsController's "Emploi du temps") - see templates/layout/app.html.twig.
// SkillGroup/Skill management (Groupes de compétences/Compétences) lives in
// ProgramSettingsController now - InternshipBookletBuilder still reads SkillGroup here.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ProgramInternshipController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/internship', name: 'app_program_internship')]
    #[Route(path: '/programs/{id}/internship/tutors', name: 'app_program_internship_tutors')]
    public function tutorsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'tutors');
    }

    #[Route(path: '/programs/{id}/internship/evaluation-periods', name: 'app_program_internship_evaluation_periods')]
    public function evaluationPeriodsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'evaluation_periods');
    }

    #[Route(path: '/programs/{id}/internship/denomination', name: 'app_program_internship_denomination')]
    public function denominationTab(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, InternshipOptionLegalNameRepository $legalNameRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $info = $infoRepository->findOneByProgram($program);
        $isNew = null === $info;

        if ($isNew) {
            $info = new InternshipProgramInfo($program);
        }

        $form = $this->createForm(InternshipLegalNameType::class, $info);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->stampAuditFields($info, !$isNew);

            $entityManager->persist($info);
            $entityManager->flush();

            $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_denomination', ['id' => $program->getId()]);
        }

        return $this->render('program/internship.html.twig', [
            'program' => $program,
            'activeTab' => 'denomination',
            'form' => $form,
            'legalNamesByOptionId' => $legalNameRepository->findMapForProgram($program),
        ]);
    }

    // Presence of a row IS the per-Option override (see InternshipOptionLegalName's docblock) -
    // same shape as updateOptionExamModalities() below.
    #[Route(path: '/programs/{id}/internship/denomination/options', name: 'app_program_internship_denomination_options', methods: ['POST'])]
    public function updateOptionLegalNames(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipOptionLegalNameRepository $legalNameRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        if (!$this->isCsrfTokenValid('program_internship_legal_names', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $submittedNames = $request->request->all('legalNames');

        foreach ($program->getOptions() as $option) {
            $raw = trim((string) ($submittedNames[$option->getId()] ?? ''));
            $existingOverride = $legalNameRepository->findOneForProgramAndOption($program, $option);

            if ('' === $raw) {
                if (null !== $existingOverride) {
                    $entityManager->remove($existingOverride);
                }

                continue;
            }

            if (null !== $existingOverride) {
                $existingOverride->setLegalName($raw);
            } else {
                $entityManager->persist(new InternshipOptionLegalName($program, $option, $raw));
            }
        }

        $entityManager->flush();
        $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

        return $this->redirectToRoute('app_program_internship_denomination', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/internship/contract-modalities', name: 'app_program_internship_contract_modalities')]
    public function contractModalitiesTab(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $info = $infoRepository->findOneByProgram($program);
        $isNew = null === $info;

        if ($isNew) {
            $info = new InternshipProgramInfo($program);
        }

        $form = $this->createForm(InternshipContractModalitiesType::class, $info);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $info->setTermsConditionsProText($this->sanitizeOrNull($sanitizer, $info->getTermsConditionsProText()));
            $info->setTermsConditionsApprentissageText($this->sanitizeOrNull($sanitizer, $info->getTermsConditionsApprentissageText()));
            $this->stampAuditFields($info, !$isNew);

            $entityManager->persist($info);
            $entityManager->flush();

            $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_contract_modalities', ['id' => $program->getId()]);
        }

        return $this->render('program/internship.html.twig', [
            'program' => $program,
            'activeTab' => 'contract_modalities',
            'form' => $form,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/exam-modalities', name: 'app_program_internship_exam_modalities')]
    public function examModalitiesTab(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, InternshipOptionExamModalityRepository $examModalityRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $info = $infoRepository->findOneByProgram($program);
        $isNew = null === $info;

        if ($isNew) {
            $info = new InternshipProgramInfo($program);
        }

        $form = $this->createForm(InternshipExamModalityType::class, $info);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $info->setExamModalityText($this->sanitizeOrNull($sanitizer, $info->getExamModalityText()));
            $this->stampAuditFields($info, !$isNew);

            $entityManager->persist($info);
            $entityManager->flush();

            $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_exam_modalities', ['id' => $program->getId()]);
        }

        return $this->render('program/internship.html.twig', [
            'program' => $program,
            'activeTab' => 'exam_modalities',
            'form' => $form,
            'examModalitiesByOptionId' => $examModalityRepository->findMapForProgram($program),
        ]);
    }

    // Presence of a row IS the per-Option override (see InternshipOptionExamModality's docblock).
    #[Route(path: '/programs/{id}/internship/exam-modalities/options', name: 'app_program_internship_exam_modalities_options', methods: ['POST'])]
    public function updateOptionExamModalities(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipOptionExamModalityRepository $examModalityRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        if (!$this->isCsrfTokenValid('program_internship_exam_modalities', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $submittedTexts = $request->request->all('examModalities');

        foreach ($program->getOptions() as $option) {
            $raw = trim($sanitizer->sanitize((string) ($submittedTexts[$option->getId()] ?? '')));
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

        return $this->redirectToRoute('app_program_internship_exam_modalities', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/internship/tutors/new', name: 'app_program_internship_tutors_new')]
    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/edit', name: 'app_program_internship_tutors_edit')]
    public function tutorLinkForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipTutorProvisioningService $provisioningService, ?int $tutorLinkId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = null !== $tutorLinkId ? $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId) : new InternshipTutorLink($program);
        $isEdit = null !== $tutorLinkId;

        // Must be resolved and set before handleRequest()/isValid() runs, not after -
        // InternshipTutorLink::$student carries an Assert\NotNull, so setting it only on success
        // would make the form permanently invalid (student is null right up to the point
        // isValid() runs). Same convention as LaptopController::resolveActiveBorrower().
        if ($request->isMethod('POST')) {
            $tutorLink->setStudent($this->resolveProgramStudent($program, $request->request->get('student')));
        }

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

    // Backs the student ajax tom-select field in internship_tutor_link_new.html.twig - only the
    // program's own students are eligible, same convention as
    // ProgramTimetableSettingsController::teachersSearch().
    #[Route(path: '/programs/{id}/internship/tutors/students-search', name: 'app_program_internship_tutors_students_search')]
    public function tutorLinkStudentsSearch(int $id, Request $request, ProgramRepository $repository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $limit = 20;
        $query = mb_strtolower((string) $request->query->get('q', ''));

        $candidates = array_values(array_filter(
            $program->getStudents()->toArray(),
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

    #[Route(path: '/programs/{id}/internship/evaluation-periods/new', name: 'app_program_internship_evaluation_periods_new')]
    #[Route(path: '/programs/{id}/internship/evaluation-periods/{evaluationPeriodId}/edit', name: 'app_program_internship_evaluation_periods_edit')]
    public function evaluationPeriodForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, ?int $evaluationPeriodId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $evaluationPeriod = null !== $evaluationPeriodId ? $this->findEvaluationPeriodOrNotFound($evaluationPeriodRepository, $program, $evaluationPeriodId) : null;
        $isEdit = null !== $evaluationPeriod;

        $form = $this->createForm(InternshipEvaluationPeriodType::class, $evaluationPeriod, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipEvaluationPeriodUpdatedFlashMessage' : 'internshipEvaluationPeriodCreatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_evaluation_periods', ['id' => $program->getId()]);
        }

        return $this->render('program/internship_evaluation_period_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/evaluation-periods/{evaluationPeriodId}/deactivate', name: 'app_program_internship_evaluation_periods_deactivate', methods: ['POST'])]
    public function deactivateEvaluationPeriod(int $id, int $evaluationPeriodId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $evaluationPeriod = $this->findEvaluationPeriodOrNotFound($evaluationPeriodRepository, $program, $evaluationPeriodId);
        $this->assertValidToken('program_internship_deactivate', $request);

        $evaluationPeriod->setInactiveDate(new \DateTimeImmutable());
        $evaluationPeriod->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/internship/evaluation-periods/data', name: 'app_program_internship_evaluation_periods_data')]
    public function evaluationPeriodsData(int $id, Request $request, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $evaluationPeriodRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $evaluationPeriodRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $evaluationPeriodRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (InternshipEvaluationPeriod $evaluationPeriod): array => [
                    'id' => $evaluationPeriod->getId(),
                    'isInactive' => null !== $evaluationPeriod->getInactiveDate(),
                    'name' => $evaluationPeriod->getName(),
                    'startDate' => $evaluationPeriod->getStartDate()?->format('d/m/Y') ?? '—',
                    'endDate' => $evaluationPeriod->getEndDate()?->format('d/m/Y') ?? '—',
                    'creationDate' => $evaluationPeriod->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $evaluationPeriod->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($evaluationPeriod->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($evaluationPeriod->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($evaluationPeriod->getLastUpdatedBy()),
                    'lastUpdatedDate' => $evaluationPeriod->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
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
    public function tutorLinkTeamEvaluations(int $id, int $tutorLinkId, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTeamEvaluationRepository $teamEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);

        $evaluationsByPeriodId = [];
        foreach ($teamEvaluationRepository->findAllForStudentAndProgram($tutorLink->getStudent(), $program) as $evaluation) {
            $evaluationsByPeriodId[$evaluation->getEvaluationPeriod()->getId()] = $evaluation;
        }

        $rows = array_map(
            static fn (InternshipEvaluationPeriod $evaluationPeriod): array => [
                'period' => $evaluationPeriod,
                'submitted' => isset($evaluationsByPeriodId[$evaluationPeriod->getId()]),
            ],
            $evaluationPeriodRepository->findAllActiveForProgram($program),
        );

        return $this->render('program/internship_tutor_team_evaluations.html.twig', [
            'program' => $program,
            'tutorLink' => $tutorLink,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/team-evaluations/{periodId}', name: 'app_program_internship_tutors_team_evaluation', requirements: ['periodId' => '\d+'])]
    public function tutorLinkTeamEvaluation(int $id, int $tutorLinkId, int $periodId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTeamEvaluationRepository $teamEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);
        $evaluationPeriod = $evaluationPeriodRepository->find($periodId) ?? throw $this->createNotFoundException();

        $evaluation = $teamEvaluationRepository->findOneForStudentAndEvaluationPeriod($tutorLink->getStudent(), $evaluationPeriod);
        $isEdit = null !== $evaluation;

        if (!$isEdit) {
            $evaluation = new InternshipTeamEvaluation($tutorLink->getStudent(), $program, $evaluationPeriod);
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
            'period' => $evaluationPeriod,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/tutors/reminders', name: 'app_program_internship_tutors_reminders')]
    public function evaluationReminders(int $id, Request $request, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, InternshipTutorLinkRepository $tutorLinkRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $period = null !== $request->query->get('period') ? $evaluationPeriodRepository->find($request->query->getInt('period')) : null;
        $pending = null !== $period ? $this->findPendingEvaluations($program, $period, $studentEvaluationRepository, $tutorEvaluationRepository, $tutorLinkRepository) : ['students' => [], 'tutorLinks' => []];

        return $this->render('program/internship_evaluation_reminders.html.twig', [
            'program' => $program,
            'periods' => $evaluationPeriodRepository->findAllActiveForProgram($program),
            'selectedPeriod' => $period,
            'pendingStudents' => $pending['students'],
            'pendingTutorLinks' => $pending['tutorLinks'],
        ]);
    }

    #[Route(path: '/programs/{id}/internship/tutors/reminders/send', name: 'app_program_internship_tutors_reminders_send', methods: ['POST'])]
    public function sendEvaluationReminders(int $id, Request $request, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, InternshipTutorLinkRepository $tutorLinkRepository, MailerInterface $mailer, TranslatorInterface $translator): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $period = $evaluationPeriodRepository->find($request->request->getInt('period')) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('program_internship_reminders_send', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $pending = $this->findPendingEvaluations($program, $period, $studentEvaluationRepository, $tutorEvaluationRepository, $tutorLinkRepository);

        $studentSubject = $translator->trans('internshipStudentEvaluationReminderEmailSubject');
        foreach ($pending['students'] as $student) {
            if (null === $student->getEmail()) {
                continue;
            }

            $mailer->send((new TemplatedEmail())
                ->to($student->getEmail())
                ->subject($studentSubject)
                ->htmlTemplate('emails/internship_student_evaluation_reminder.html.twig')
                ->context(['program' => $program, 'period' => $period, 'student' => $student]));
        }

        $tutorSubject = $translator->trans('internshipTutorEvaluationReminderEmailSubject');
        foreach ($pending['tutorLinks'] as $tutorLink) {
            $mailer->send((new TemplatedEmail())
                ->to($tutorLink->getTutorEmail())
                ->subject($tutorSubject)
                ->htmlTemplate('emails/internship_tutor_evaluation_reminder.html.twig')
                ->context(['program' => $program, 'period' => $period, 'tutorLink' => $tutorLink]));
        }

        $this->addFlash('success', $translator->trans('internshipEvaluationRemindersSentFlashMessage', [
            '%count%' => \count($pending['students']) + \count($pending['tutorLinks']),
        ]));

        return $this->redirectToRoute('app_program_internship_tutors_reminders', ['id' => $program->getId(), 'period' => $period->getId()]);
    }

    /** @return array{students: list<User>, tutorLinks: list<InternshipTutorLink>} */
    private function findPendingEvaluations(Program $program, InternshipEvaluationPeriod $evaluationPeriod, InternshipStudentEvaluationRepository $studentEvaluationRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, InternshipTutorLinkRepository $tutorLinkRepository): array
    {
        $submittedStudentIds = $studentEvaluationRepository->findSubmittedStudentIdsForProgramAndEvaluationPeriod($program, $evaluationPeriod);
        $pendingStudents = array_values(array_filter(
            $program->getStudents()->toArray(),
            static fn (User $student): bool => !\in_array($student->getId(), $submittedStudentIds, true),
        ));

        $submittedTutorLinkIds = $tutorEvaluationRepository->findSubmittedTutorLinkIdsForProgramAndEvaluationPeriod($program, $evaluationPeriod);
        $pendingTutorLinks = array_values(array_filter(
            $tutorLinkRepository->findAllActiveForProgram($program),
            static fn (InternshipTutorLink $tutorLink): bool => !\in_array($tutorLink->getId(), $submittedTutorLinkIds, true),
        ));

        return ['students' => $pendingStudents, 'tutorLinks' => $pendingTutorLinks];
    }

    // One row per (active InternshipTutorLink x active InternshipEvaluationPeriod) for the
    // program - a fuller, always-visible picture than the "Rappels" screen above, which only
    // ever shows one selected period's pending list. Sorted late-first so the most urgent rows
    // surface immediately; clicking any row (submitted or not) opens tutorEvaluation() below to
    // view/edit it on the tutor's behalf.
    #[Route(path: '/programs/{id}/internship/tutors/evaluations', name: 'app_program_internship_tutors_evaluations')]
    public function tutorEvaluationsStatus(int $id, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $evaluationPeriods = $evaluationPeriodRepository->findAllActiveForProgram($program);

        $rows = [];
        foreach ($tutorLinkRepository->findAllActiveForProgram($program) as $tutorLink) {
            $evaluationsByPeriodId = [];
            foreach ($tutorEvaluationRepository->findAllForTutorLink($tutorLink) as $evaluation) {
                $evaluationsByPeriodId[$evaluation->getEvaluationPeriod()->getId()] = $evaluation;
            }

            foreach ($evaluationPeriods as $evaluationPeriod) {
                $evaluation = $evaluationsByPeriodId[$evaluationPeriod->getId()] ?? null;

                $rows[] = [
                    'tutorLink' => $tutorLink,
                    'evaluationPeriod' => $evaluationPeriod,
                    'evaluation' => $evaluation,
                    'status' => match (true) {
                        null !== $evaluation => 'submitted',
                        $evaluationPeriod->isPast() => 'late',
                        default => 'pending',
                    },
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => self::evaluationStatusSortWeight($a['status']) <=> self::evaluationStatusSortWeight($b['status']));

        return $this->render('program/internship_tutor_evaluations_status.html.twig', [
            'program' => $program,
            'rows' => $rows,
        ]);
    }

    private static function evaluationStatusSortWeight(string $status): int
    {
        return match ($status) {
            'late' => 0,
            'pending' => 1,
            'submitted' => 2,
        };
    }

    // Staff view/edit of an InternshipTutorEvaluation on the tutor's own behalf - same
    // InternshipTutorEvaluationBuilder find-or-create + pre-population logic and the same
    // InternshipTutorEvaluationType form as the tutor's own InternshipTutorEvaluationController::
    // evaluate(), just reached from the staff status screen above instead of ROLE_EXTERNAL's own
    // area, and stamping $lastEditedBy with the staff member instead of the tutor.
    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/evaluations/{evaluationPeriodId}', name: 'app_program_internship_tutors_evaluation', requirements: ['evaluationPeriodId' => '\d+'])]
    public function tutorEvaluation(int $id, int $tutorLinkId, int $evaluationPeriodId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTutorEvaluationBuilder $evaluationBuilder, SkillLevelRepository $skillLevelRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);
        $evaluationPeriod = $this->findEvaluationPeriodOrNotFound($evaluationPeriodRepository, $program, $evaluationPeriodId);

        ['evaluation' => $evaluation, 'isEdit' => $isEdit, 'skillGroups' => $skillGroups] = $evaluationBuilder->findOrPrepare($tutorLink, $evaluationPeriod);

        $skillLevels = $skillLevelRepository->findAllActiveForProgramOrGlobal($program);
        $form = $this->createForm(InternshipTutorEvaluationType::class, $evaluation, ['skillLevelChoices' => $skillLevels]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setValidationDate(new \DateTimeImmutable());
            $entity->setLastEditedBy($this->currentUser());
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'internshipTutorEvaluationSavedFlashMessage');

            return $this->redirectToRoute('app_program_internship_tutors_evaluations', ['id' => $program->getId()]);
        }

        return $this->render('program/internship_tutor_evaluation.html.twig', [
            'form' => $form,
            'program' => $program,
            'tutorLink' => $tutorLink,
            'period' => $evaluationPeriod,
            'skillGroups' => $skillGroups,
        ]);
    }

    // One row per (Program student x active InternshipEvaluationPeriod) - same shape as
    // tutorEvaluationsStatus() above, for student self-evaluations instead of tutor ones.
    #[Route(path: '/programs/{id}/internship/students/evaluations', name: 'app_program_internship_students_evaluations')]
    public function studentEvaluationsStatus(int $id, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $evaluationPeriods = $evaluationPeriodRepository->findAllActiveForProgram($program);

        $rows = [];
        foreach ($program->getStudents() as $student) {
            $evaluationsByPeriodId = [];
            foreach ($studentEvaluationRepository->findAllForStudentAndProgram($student, $program) as $evaluation) {
                $evaluationsByPeriodId[$evaluation->getEvaluationPeriod()->getId()] = $evaluation;
            }

            foreach ($evaluationPeriods as $evaluationPeriod) {
                $evaluation = $evaluationsByPeriodId[$evaluationPeriod->getId()] ?? null;

                $rows[] = [
                    'student' => $student,
                    'evaluationPeriod' => $evaluationPeriod,
                    'evaluation' => $evaluation,
                    'status' => match (true) {
                        null !== $evaluation => 'submitted',
                        $evaluationPeriod->isPast() => 'late',
                        default => 'pending',
                    },
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => self::evaluationStatusSortWeight($a['status']) <=> self::evaluationStatusSortWeight($b['status']));

        return $this->render('program/internship_student_evaluations_status.html.twig', [
            'program' => $program,
            'rows' => $rows,
        ]);
    }

    // Staff view/edit of an InternshipStudentEvaluation on the student's own behalf - same form
    // as the student's own ProgramInternshipEvaluationController::myEvaluation(), just reached
    // from the staff status screen above and stamping $lastEditedBy with the staff member.
    #[Route(path: '/programs/{id}/internship/students/{studentId}/evaluations/{evaluationPeriodId}', name: 'app_program_internship_students_evaluation', requirements: ['evaluationPeriodId' => '\d+'])]
    public function studentEvaluation(int $id, int $studentId, int $evaluationPeriodId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $student = $this->findProgramStudentOrNotFound($program, $studentId);
        $evaluationPeriod = $this->findEvaluationPeriodOrNotFound($evaluationPeriodRepository, $program, $evaluationPeriodId);

        $evaluation = $studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $evaluationPeriod);
        $isEdit = null !== $evaluation;

        if (!$isEdit) {
            $evaluation = new InternshipStudentEvaluation($student, $program, $evaluationPeriod);
        }

        $form = $this->createForm(InternshipStudentEvaluationType::class, $evaluation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setValidationDate(new \DateTimeImmutable());
            $entity->setLastEditedBy($this->currentUser());
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'internshipStudentEvaluationSavedFlashMessage');

            return $this->redirectToRoute('app_program_internship_students_evaluations', ['id' => $program->getId()]);
        }

        return $this->render('program/internship_student_evaluation.html.twig', [
            'form' => $form,
            'program' => $program,
            'student' => $student,
            'period' => $evaluationPeriod,
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

    private function findEvaluationPeriodOrNotFound(InternshipEvaluationPeriodRepository $repository, Program $program, int $evaluationPeriodId): InternshipEvaluationPeriod
    {
        $evaluationPeriod = $repository->find($evaluationPeriodId) ?? throw $this->createNotFoundException();

        if ($evaluationPeriod->getProgram()?->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $evaluationPeriod;
    }

    private function findProgramStudentOrNotFound(Program $program, int $studentId): User
    {
        return $this->resolveProgramStudent($program, $studentId) ?? throw $this->createNotFoundException();
    }

    // Re-resolves and re-checks the submitted student id server-side rather than trusting it -
    // same reasoning as LaptopController::resolveActiveBorrower().
    private function resolveProgramStudent(Program $program, mixed $studentId): ?User
    {
        if (!is_numeric($studentId)) {
            return null;
        }

        foreach ($program->getStudents() as $student) {
            if ($student->getId() === (int) $studentId) {
                return $student;
            }
        }

        return null;
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

    // HugeRTE-authored HTML rendered back on the booklet - sanitized the same way as
    // Announcement::$body/Message::$body (design/validated/internal-messaging.md).
    private function sanitizeOrNull(HtmlSanitizerInterface $sanitizer, ?string $html): ?string
    {
        return null !== $html && '' !== $html ? $sanitizer->sanitize($html) : $html;
    }
}
