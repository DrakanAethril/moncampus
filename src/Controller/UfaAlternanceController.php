<?php

namespace App\Controller;

use App\Entity\InternshipStudentEvaluation;
use App\Entity\InternshipSupervisorEvaluation;
use App\Entity\InternshipTeamEvaluation;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\AlternanceReminderStep;
use App\Enum\ContractTypeCode;
use App\Form\InternshipAlternanceType;
use App\Form\InternshipStudentEvaluationType;
use App\Form\InternshipTeamEvaluationType;
use App\Form\InternshipTutorLinkType;
use App\Repository\EnterpriseRepository;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipReminderRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Service\AlternanceEngagementService;
use App\Service\AlternancePeriodStatusResolver;
use App\Service\AlternancePeriodWizardService;
use App\Service\AlternanceReminderService;
use App\Service\AlternanceStepStatus;
use App\Service\AlternanceTutorWizardStepBuilder;
use App\Service\GotenbergUnavailableException;
use App\Service\InternshipBookletBuilder;
use App\Service\InternshipBookletPdfExporter;
use App\Service\InternshipTutorProvisioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The new UFA "Alternances" surface (design/design_handoff_ufa_alternance, tours 32-34): the
 * dashboard replacing the old Formations-list at /ufa (see UfaController's own docblock - that
 * controller keeps the Formations tabs/Configuration/placeholder routes, this one owns everything
 * under /ufa/alternances plus the repointed /ufa itself), and "Créer une alternance". Later phases
 * add the engagement/period-wizard/suivi/relance/livret routes to this same controller.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class UfaAlternanceController extends AbstractController
{
    #[Route(path: '/ufa', name: 'app_ufa')]
    public function dashboard(Request $request, SchoolYearRepository $schoolYearRepository, ProgramRepository $programRepository, InternshipTutorLinkRepository $tutorLinkRepository, EnterpriseRepository $enterpriseRepository, AlternancePeriodStatusResolver $statusResolver, TranslatorInterface $translator): Response
    {
        $currentSchoolYear = $schoolYearRepository->findCurrentOrMostRecent();
        $schoolYears = $schoolYearRepository->findAllActiveOrderedByMostRecent();

        $selectedYearId = $request->query->getInt('year', 0);
        $selectedYear = 0 !== $selectedYearId ? $this->findSchoolYearOrNotFound($schoolYears, $selectedYearId) : $currentSchoolYear;
        $isPastYear = null !== $currentSchoolYear && null !== $selectedYear && $selectedYear->getId() !== $currentSchoolYear->getId();

        $formations = null !== $selectedYear ? $programRepository->findAlternanceForSchoolYear($selectedYear, true) : [];

        $selectedFormationId = $request->query->getInt('formation', 0);
        $selectedFormation = 0 !== $selectedFormationId
            ? $this->findFormationOrNotFound($formations, $selectedFormationId)
            : ($formations[0] ?? null);

        $selectedEnterpriseId = $request->query->getInt('enterprise', 0);
        $selectedEnterprise = 0 !== $selectedEnterpriseId ? $enterpriseRepository->find($selectedEnterpriseId) : null;

        $showAll = $request->query->getBoolean('all');
        $search = trim((string) $request->query->get('search', ''));

        $rows = [];
        if (null !== $selectedFormation) {
            foreach ($tutorLinkRepository->findForDashboard($selectedFormation, $showAll, $selectedEnterprise, '' !== $search ? $search : null) as $tutorLink) {
                $status = $statusResolver->resolveCurrentStep($tutorLink);
                $rows[] = [
                    'tutorLink' => $tutorLink,
                    'status' => $status,
                    'badge' => $isPastYear && null === $tutorLink->getInactiveDate() && AlternanceStepStatus::STEP_INACTIVE !== $status->step
                        ? ['label' => $translator->trans('ufaAlternanceStatusYearClosedBadgeLabel'), 'class' => 'bg-green-lt']
                        : $statusResolver->badgeFor($status),
                    'isPastYear' => $isPastYear,
                ];
            }
        }

        return $this->render('ufa/alternance/dashboard.html.twig', [
            'schoolYears' => $schoolYears,
            'selectedYear' => $selectedYear,
            'isPastYear' => $isPastYear,
            'formations' => $formations,
            'selectedFormation' => $selectedFormation,
            'enterprises' => $enterpriseRepository->findAllActiveOrderedByName(),
            'selectedEnterprise' => $selectedEnterprise,
            'showAll' => $showAll,
            'search' => $search,
            'rows' => $rows,
            'kpiTotal' => null !== $selectedYear ? $tutorLinkRepository->countActiveForSchoolYear($selectedYear) : 0,
            'kpiFormations' => \count($formations),
            'kpiApprentissage' => null !== $selectedYear ? $tutorLinkRepository->countActiveForSchoolYearAndContractType($selectedYear, ContractTypeCode::Apprentissage) : 0,
            'kpiProfessionnalisation' => null !== $selectedYear ? $tutorLinkRepository->countActiveForSchoolYearAndContractType($selectedYear, ContractTypeCode::Professionnalisation) : 0,
        ]);
    }

    // Tuteurs annuaire (26b) - replaces the old placeholder route, moved here (rather than staying
    // in UfaController) for cohesion with searchDistinctTutors(), which this and the "Créer une
    // alternance" tutor-search ajax field both call. This screen's exact column set is inferred:
    // the mockup's own "Liens tuteur" tab for this route was explicitly superseded by the
    // Alternances dashboard (33a/33b) once that existed, so its replacement content (a plain
    // directory: name, contact, entreprise, nb d'alternances actives) wasn't dictated verbatim.
    #[Route(path: '/ufa/tuteurs', name: 'app_ufa_tutors')]
    public function tutors(InternshipTutorLinkRepository $tutorLinkRepository): Response
    {
        $rows = array_map(
            static fn (InternshipTutorLink $link): array => ['tutorLink' => $link, 'activeCount' => $tutorLinkRepository->countActiveForTutorEmail($link->getTutorEmail())],
            $tutorLinkRepository->searchDistinctTutors('', \PHP_INT_MAX),
        );

        return $this->render('ufa/alternance/tutors.html.twig', ['rows' => $rows]);
    }

    #[Route(path: '/ufa/alternances/new', name: 'app_ufa_alternance_new')]
    public function createAlternance(Request $request, EntityManagerInterface $entityManager, SchoolYearRepository $schoolYearRepository, ProgramRepository $programRepository, InternshipTutorProvisioningService $provisioningService, AlternanceEngagementService $engagementService): Response
    {
        $schoolYear = $schoolYearRepository->findCurrentOrMostRecent() ?? throw $this->createNotFoundException();
        $alternancePrograms = $programRepository->findAlternanceForSchoolYear($schoolYear);

        $student = null;
        $program = null;
        if ($request->isMethod('POST')) {
            [$student, $program] = $this->resolveAlternanceStudent($alternancePrograms, $request->request->get('student'));
        }

        // A Program is required to construct InternshipTutorLink - fall back to the first
        // alternance Program of the year until a student is actually picked, purely so the form
        // object can exist before submission; $program is re-resolved from the picked student on
        // every POST above, so this fallback never survives an actual submit.
        $tutorLink = new InternshipTutorLink($program ?? $alternancePrograms[0] ?? throw $this->createNotFoundException());
        if (null !== $student) {
            $tutorLink->setStudent($student);
        }

        $form = $this->createForm(InternshipAlternanceType::class, $tutorLink);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $student) {
            if (null === $tutorLink->getTutor()) {
                $provisioningService->provision($tutorLink, $this->currentUser());
            }

            $tutorLink->setSupervisor($program?->getReferentTeachers()->first() ?: null);
            $tutorLink->setCreatedBy($this->currentUser());

            $entityManager->persist($tutorLink);
            $entityManager->flush();

            $engagementService->findOrCreate($tutorLink);
            $engagementService->sendEngagementInvites($tutorLink);

            $this->addFlash('success', 'ufaAlternanceCreatedFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
        }

        return $this->render('ufa/alternance/new.html.twig', [
            'form' => $form,
            'student' => $student,
            'program' => $program,
        ]);
    }

    // "Modifier" row action from the dashboard (33a) - reuses InternshipTutorLinkType (the older
    // Program-level edit form, which already carries every editable field incl. contractType and
    // the existing/new-enterprise picker) rather than InternshipAlternanceType, whose
    // tuteur-existant/nouveau toggles only make sense at creation time. Same pre-handleRequest
    // student resolution as ProgramInternshipController::tutorLinkForm() and for the same reason
    // (Assert\NotNull on $student).
    #[Route(path: '/ufa/alternances/{id}/edit', name: 'app_ufa_alternance_edit', requirements: ['id' => '\d+'])]
    public function editAlternance(int $id, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipTutorProvisioningService $provisioningService): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $program = $tutorLink->getProgram();

        if ($request->isMethod('POST')) {
            $tutorLink->setStudent($this->resolveProgramStudent($program, $request->request->get('student')));
        }

        $form = $this->createForm(InternshipTutorLinkType::class, $tutorLink, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null === $tutorLink->getTutor()) {
                $provisioningService->provision($tutorLink, $this->currentUser());
            }

            $tutorLink->setLastUpdatedBy($this->currentUser());
            $tutorLink->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'internshipTutorLinkUpdatedFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
        }

        return $this->render('ufa/alternance/edit.html.twig', [
            'form' => $form,
            'tutorLink' => $tutorLink,
            'program' => $program,
        ]);
    }

    // "Suivi de l'alternance" (34a/34b) - the per-alternance hub: contextual relance banner,
    // engagement summary, and one row per period whose 4-role chain links into each role's wizard.
    #[Route(path: '/ufa/alternances/{id}', name: 'app_ufa_alternance_show', requirements: ['id' => '\d+'])]
    public function show(int $id, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $periodRepository, AlternancePeriodStatusResolver $statusResolver, AlternanceEngagementService $engagementService, InternshipReminderRepository $reminderRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository, InternshipTeamEvaluationRepository $teamEvaluationRepository, InternshipSupervisorEvaluationRepository $supervisorEvaluationRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $student = $tutorLink->getStudent();
        $currentStatus = $statusResolver->resolveCurrentStep($tutorLink);
        $engagement = $engagementService->findOrCreate($tutorLink);

        // The 4 per-role evaluations are loaded per period to feed each row's role-progress strip
        // (34a) - the same chips as the wizards' own header, here doubling as the navigation into
        // each role's wizard.
        $periodRows = array_map(
            fn ($period) => [
                'period' => $period,
                'status' => $statusResolver->resolveStepForPeriod($tutorLink, $period),
                'badge' => $statusResolver->badgeFor($statusResolver->resolveStepForPeriod($tutorLink, $period)),
                'tutorEvaluation' => $tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period),
                'studentEvaluation' => null !== $student ? $studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period) : null,
                'teamEvaluation' => null !== $student ? $teamEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period) : null,
                'supervisorEvaluation' => $supervisorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period),
            ],
            $periodRepository->findAllActiveForProgram($tutorLink->getProgram()),
        );

        $reminders = $reminderRepository->findAllForTutorLinkOrderedByMostRecent($tutorLink);

        return $this->render('ufa/alternance/show.html.twig', [
            'tutorLink' => $tutorLink,
            'engagement' => $engagement,
            'currentStatus' => $currentStatus,
            'periodRows' => $periodRows,
            'lastReminder' => $reminders[0] ?? null,
            'canRemind' => $currentStatus->isLate && null !== $this->reminderStepFor($currentStatus->step),
        ]);
    }

    // Staff view of the engagement (27b) - can view all 3 signature states and sign only the
    // centre-representative box; the tutor's and student's own signatures always come from their
    // own self-service routes (InternshipTutorEvaluationController::engagement() /
    // ProgramInternshipEvaluationController::myEngagement()), never on their behalf here.
    #[Route(path: '/ufa/alternances/{id}/engagement', name: 'app_ufa_alternance_engagement', requirements: ['id' => '\d+'])]
    public function engagement(int $id, InternshipTutorLinkRepository $tutorLinkRepository, AlternanceEngagementService $engagementService): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $engagement = $engagementService->findOrCreate($tutorLink);

        return $this->render('ufa/alternance/engagement.html.twig', [
            'tutorLink' => $tutorLink,
            'engagement' => $engagement,
        ]);
    }

    #[Route(path: '/ufa/alternances/{id}/engagement/sign', name: 'app_ufa_alternance_engagement_sign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function engagementSign(int $id, Request $request, InternshipTutorLinkRepository $tutorLinkRepository, AlternanceEngagementService $engagementService): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $this->assertValidFormToken('ufa_alternance_engagement_sign', $request);
        $engagement = $engagementService->findOrCreate($tutorLink);

        try {
            $engagementService->signAsCenter($engagement, $this->currentUser());
            $this->addFlash('success', 'ufaAlternanceEngagementSignedFlashMessage');
        } catch (\DomainException) {
            $this->addFlash('error', 'ufaAlternanceEngagementSignBlockedFlashMessage');
        }

        return $this->redirectToRoute('app_ufa_alternance_engagement', ['id' => $tutorLink->getId()]);
    }

    // Staff "view/act on behalf" tuteur wizard (28a-28d) - the tutor's own self-service wizard is
    // InternshipTutorEvaluationController::periodStep(), both share
    // AlternanceTutorWizardStepBuilder for the actual form/entity logic (see the feature's plan
    // doc, §0.8, on why these are dual-mounted instead of one shared route).
    #[Route(path: '/ufa/alternances/{id}/periodes/{periodId}/tuteur/{step}', name: 'app_ufa_alternance_period_tuteur', requirements: ['id' => '\d+', 'periodId' => '\d+', 'step' => 'comportement|competences|forces|remarques'])]
    public function periodTuteur(int $id, int $periodId, string $step, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $periodRepository, AlternancePeriodWizardService $wizardService, AlternanceTutorWizardStepBuilder $stepBuilder, TranslatorInterface $translator): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $period = $periodRepository->find($periodId) ?? throw $this->createNotFoundException();

        if (!$wizardService->arePeriodsOpen($tutorLink)) {
            $this->addFlash('warning', 'ufaAlternanceWizardPeriodsNotOpenFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_engagement', ['id' => $tutorLink->getId()]);
        }

        $evaluation = $stepBuilder->findOrPrepare($tutorLink, $period);
        $readOnly = $wizardService->isTutorStepReadOnly($tutorLink, $period);
        $form = $stepBuilder->buildStepForm($step, $evaluation, $tutorLink->getProgram());

        if (!$readOnly) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $this->persistTutorStep($entityManager, $evaluation, $request, $this->currentUser());

                $nextStep = $stepBuilder->nextStep($step);
                if ('sign' === $request->request->get('action') && null === $nextStep) {
                    return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
                }

                return $this->redirectToRoute('app_ufa_alternance_period_tuteur', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => $nextStep ?? $step]);
            }
        }

        return $this->render('ufa/alternance/period_tuteur.html.twig', [
            'tutorLink' => $tutorLink,
            'period' => $period,
            'step' => $step,
            'form' => $form,
            ...$wizardService->evaluationsFor($tutorLink, $period),
            'tutorEvaluation' => $evaluation,
            'readOnly' => $readOnly,
            'backPath' => $stepBuilder->previousStep($step) ? $this->generateUrl('app_ufa_alternance_period_tuteur', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => $stepBuilder->previousStep($step)]) : null,
            'stepLabels' => array_map(static fn (string $s): string => $translator->trans($stepBuilder->stepLabel($s)), AlternanceTutorWizardStepBuilder::STEPS),
            'currentStepIndex' => array_search($step, AlternanceTutorWizardStepBuilder::STEPS, true) + 1,
            'helperText' => $translator->trans('ufaAlternanceWizardTuteurNoIntermediateSaveHelpText'),
            'signLabel' => $translator->trans('ufaAlternanceWizardTuteurSignButtonLabel'),
            'showSaveButton' => false,
        ]);
    }

    // Staff "view/act on behalf" alternant wizard (29a-29d) - steps 1-3 render the tutor's own
    // evaluation read-only, step 4 is the alternant's own remarksText + signature.
    #[Route(path: '/ufa/alternances/{id}/periodes/{periodId}/alternant/{step}', name: 'app_ufa_alternance_period_alternant', requirements: ['id' => '\d+', 'periodId' => '\d+', 'step' => 'comportement|competences|forces|remarques'])]
    public function periodAlternant(int $id, int $periodId, string $step, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $periodRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository, AlternancePeriodWizardService $wizardService, AlternanceTutorWizardStepBuilder $stepBuilder, TranslatorInterface $translator): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $period = $periodRepository->find($periodId) ?? throw $this->createNotFoundException();

        if (!$wizardService->isStudentStepOpen($tutorLink, $period)) {
            $this->addFlash('warning', 'ufaAlternanceWizardStepNotOpenFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
        }

        $tutorEvaluation = $tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period);
        $student = $tutorLink->getStudent() ?? throw $this->createNotFoundException();
        $studentEvaluation = $studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period)
            ?? new InternshipStudentEvaluation($student, $tutorLink->getProgram(), $period);
        $readOnly = $wizardService->isStudentStepReadOnly($tutorLink, $period);

        $form = $this->createForm(InternshipStudentEvaluationType::class, $studentEvaluation);

        if ('remarques' === $step && !$readOnly) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $this->persistStudentStep($entityManager, $studentEvaluation, $request, $this->currentUser());

                return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
            }
        }

        return $this->render('ufa/alternance/period_alternant.html.twig', [
            'tutorLink' => $tutorLink,
            'period' => $period,
            'step' => $step,
            'form' => $form,
            ...$wizardService->evaluationsFor($tutorLink, $period),
            'tutorEvaluation' => $tutorEvaluation,
            'studentEvaluation' => $studentEvaluation,
            'readOnly' => $readOnly,
            'backPath' => $stepBuilder->previousStep($step) ? $this->generateUrl('app_ufa_alternance_period_alternant', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => $stepBuilder->previousStep($step)]) : null,
            'stepLabels' => [
                $translator->trans('ufaAlternanceWizardStepComportementLabel'),
                $translator->trans('ufaAlternanceWizardStepCompetencesLabel'),
                $translator->trans('ufaAlternanceWizardStepStrengthsLabel'),
                $translator->trans('ufaAlternanceWizardStepAlternantRemarquesLabel'),
            ],
            'currentStepIndex' => array_search($step, AlternanceTutorWizardStepBuilder::STEPS, true) + 1,
        ]);
    }

    private function persistTutorStep(EntityManagerInterface $entityManager, InternshipTutorEvaluation $evaluation, Request $request, User $actor): void
    {
        $evaluation->setValidationDate(new \DateTimeImmutable());
        $evaluation->setLastEditedBy($actor);

        if ('sign' === $request->request->get('action')) {
            $evaluation->setSignedAt(new \DateTimeImmutable());
            $evaluation->setSignedBy($actor);
        }

        if (null === $evaluation->getCreatedBy()) {
            $evaluation->setCreatedBy($actor);
        }

        $entityManager->persist($evaluation);
        $entityManager->flush();
    }

    private function persistStudentStep(EntityManagerInterface $entityManager, InternshipStudentEvaluation $evaluation, Request $request, User $actor): void
    {
        $evaluation->setValidationDate(new \DateTimeImmutable());
        $evaluation->setLastEditedBy($actor);
        $evaluation->setSignedAt(new \DateTimeImmutable());
        $evaluation->setSignedBy($actor);

        if (null === $evaluation->getCreatedBy()) {
            $evaluation->setCreatedBy($actor);
        }

        $entityManager->persist($evaluation);
        $entityManager->flush();
    }

    // Équipe pédagogique wizard (30c/30d) - staff-only, no self-service duality. Steps 1-2 are the
    // same read-only tutor grids as the alternant's; step 3 groups the tutor's strengths/
    // weaknesses/goals + the tutor's and alternant's own remarks, always read-only here (the
    // chargé de suivi's step 3, periodSuivi() below, reuses the same partial in editable mode);
    // step 4 is the team's own remark + signature.
    #[Route(path: '/ufa/alternances/{id}/periodes/{periodId}/equipe/{step}', name: 'app_ufa_alternance_period_equipe', requirements: ['id' => '\d+', 'periodId' => '\d+', 'step' => 'comportement|competences|forces|remarques'])]
    public function periodEquipe(int $id, int $periodId, string $step, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $periodRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository, InternshipTeamEvaluationRepository $teamEvaluationRepository, AlternancePeriodWizardService $wizardService, TranslatorInterface $translator): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $period = $periodRepository->find($periodId) ?? throw $this->createNotFoundException();
        $student = $tutorLink->getStudent() ?? throw $this->createNotFoundException();

        if (!$wizardService->isTeamStepOpen($tutorLink, $period)) {
            $this->addFlash('warning', 'ufaAlternanceWizardStepNotOpenFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
        }

        $tutorEvaluation = $tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period);
        $studentEvaluation = $studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period);
        $teamEvaluation = $teamEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period)
            ?? new InternshipTeamEvaluation($student, $tutorLink->getProgram(), $period);
        $readOnly = $wizardService->isTeamStepReadOnly($tutorLink, $period);

        $form = $this->createForm(InternshipTeamEvaluationType::class, $teamEvaluation);
        if ('remarques' === $step && !$readOnly) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $teamEvaluation->setValidationDate(new \DateTimeImmutable());
                $teamEvaluation->setSignedAt(new \DateTimeImmutable());
                $teamEvaluation->setSignedBy($this->currentUser());
                if (null === $teamEvaluation->getCreatedBy()) {
                    $teamEvaluation->setCreatedBy($this->currentUser());
                }
                $entityManager->persist($teamEvaluation);
                $entityManager->flush();

                return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
            }
        }

        $steps = AlternanceTutorWizardStepBuilder::STEPS;
        $stepIndex = array_search($step, $steps, true);

        return $this->render('ufa/alternance/period_equipe.html.twig', [
            'tutorLink' => $tutorLink,
            'period' => $period,
            'step' => $step,
            'form' => $form,
            ...$wizardService->evaluationsFor($tutorLink, $period),
            'tutorEvaluation' => $tutorEvaluation,
            'studentEvaluation' => $studentEvaluation,
            'teamEvaluation' => $teamEvaluation,
            'readOnly' => $readOnly,
            'backPath' => $stepIndex > 0 ? $this->generateUrl('app_ufa_alternance_period_equipe', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => $steps[$stepIndex - 1]]) : null,
            'stepLabels' => [
                $translator->trans('ufaAlternanceWizardStepComportementLabel'),
                $translator->trans('ufaAlternanceWizardStepCompetencesLabel'),
                $translator->trans('ufaAlternanceWizardStepEquipeGroupedLabel'),
                $translator->trans('ufaAlternanceWizardStepEquipeRemarquesLabel'),
            ],
            'currentStepIndex' => $stepIndex + 1,
        ]);
    }

    // Chargé de suivi wizard (31a/31c/31d) - staff-only. Steps 1-2 reuse the exact same step
    // forms as the tuteur's own wizard (AlternanceTutorWizardStepBuilder), over the same
    // InternshipTutorEvaluation entity, but always editable with "Enregistrer cette étape" rather
    // than signing anything; step 3 is _wizard_remarks_grouped.html.twig in editable mode (a
    // plain multi-entity sync, not a single Symfony Form - see that partial's own docblock); step
    // 4 "Clôture" has no fields, one click both signs and closes the period.
    #[Route(path: '/ufa/alternances/{id}/periodes/{periodId}/suivi/{step}', name: 'app_ufa_alternance_period_suivi', requirements: ['id' => '\d+', 'periodId' => '\d+', 'step' => 'comportement|competences|forces|remarques'])]
    public function periodSuivi(int $id, int $periodId, string $step, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $periodRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository, InternshipTeamEvaluationRepository $teamEvaluationRepository, InternshipSupervisorEvaluationRepository $supervisorEvaluationRepository, AlternancePeriodWizardService $wizardService, AlternanceTutorWizardStepBuilder $stepBuilder, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer, TranslatorInterface $translator): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $period = $periodRepository->find($periodId) ?? throw $this->createNotFoundException();
        $student = $tutorLink->getStudent() ?? throw $this->createNotFoundException();

        if (!$wizardService->isSupervisorStepOpen($tutorLink, $period)) {
            $this->addFlash('warning', 'ufaAlternanceWizardStepNotOpenFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
        }

        $tutorEvaluation = $stepBuilder->findOrPrepare($tutorLink, $period);
        $studentEvaluation = $studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period) ?? new InternshipStudentEvaluation($student, $tutorLink->getProgram(), $period);
        $teamEvaluation = $teamEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period) ?? new InternshipTeamEvaluation($student, $tutorLink->getProgram(), $period);
        $supervisorEvaluation = $supervisorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period) ?? new InternshipSupervisorEvaluation($tutorLink, $period);
        $isClosed = $wizardService->isPeriodClosed($tutorLink, $period);

        $form = \in_array($step, ['comportement', 'competences'], true) ? $stepBuilder->buildStepForm($step, $tutorEvaluation, $tutorLink->getProgram()) : null;

        if ($request->isMethod('POST') && !$isClosed) {
            if (null !== $form) {
                $form->handleRequest($request);
                if ($form->isSubmitted() && $form->isValid()) {
                    $tutorEvaluation->setValidationDate(new \DateTimeImmutable());
                    $tutorEvaluation->setLastEditedBy($this->currentUser());
                    if (null === $tutorEvaluation->getCreatedBy()) {
                        $tutorEvaluation->setCreatedBy($this->currentUser());
                    }
                    $entityManager->persist($tutorEvaluation);
                    $entityManager->flush();

                    return $this->redirectToRoute('app_ufa_alternance_period_suivi', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => 'save' === $request->request->get('action') ? $step : $stepBuilder->nextStep($step)]);
                }
            } elseif ('forces' === $step) {
                $tutorEvaluation->setStrengthsText($sanitizer->sanitize((string) $request->request->get('tutorStrengthsText')));
                $tutorEvaluation->setWeaknessesText($sanitizer->sanitize((string) $request->request->get('tutorWeaknessesText')));
                $tutorEvaluation->setGoalsText($sanitizer->sanitize((string) $request->request->get('tutorGoalsText')));
                $tutorEvaluation->setRemarksText($sanitizer->sanitize((string) $request->request->get('tutorRemarksText')));
                $tutorEvaluation->setLastEditedBy($this->currentUser());
                $studentEvaluation->setRemarksText($sanitizer->sanitize((string) $request->request->get('studentRemarksText')));
                $studentEvaluation->setLastEditedBy($this->currentUser());
                if (null === $studentEvaluation->getCreatedBy()) {
                    $studentEvaluation->setCreatedBy($this->currentUser());
                }
                $teamEvaluation->setRemarksText($sanitizer->sanitize((string) $request->request->get('teamRemarksText')));
                if (null === $teamEvaluation->getCreatedBy()) {
                    $teamEvaluation->setCreatedBy($this->currentUser());
                }
                $entityManager->persist($tutorEvaluation);
                $entityManager->persist($studentEvaluation);
                $entityManager->persist($teamEvaluation);
                $entityManager->flush();

                return $this->redirectToRoute('app_ufa_alternance_period_suivi', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => 'save' === $request->request->get('action') ? $step : 'remarques']);
            } elseif ('remarques' === $step && $this->isCsrfTokenValid('ufa_alternance_period_suivi_close', $request->request->get('_token'))) {
                $now = new \DateTimeImmutable();
                $supervisorEvaluation->setSupervisorSignedAt($now);
                $supervisorEvaluation->setSupervisorSignedBy($this->currentUser());
                $supervisorEvaluation->setClosedAt($now);
                $supervisorEvaluation->setClosedBy($this->currentUser());
                if (null === $supervisorEvaluation->getCreatedBy()) {
                    $supervisorEvaluation->setCreatedBy($this->currentUser());
                }
                $entityManager->persist($supervisorEvaluation);
                $entityManager->flush();

                $this->addFlash('success', 'ufaAlternanceWizardSuiviClosedFlashMessage');

                return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
            }
        }

        $steps = AlternanceTutorWizardStepBuilder::STEPS;
        $stepIndex = array_search($step, $steps, true);

        return $this->render('ufa/alternance/period_suivi.html.twig', [
            'tutorLink' => $tutorLink,
            'period' => $period,
            'step' => $step,
            'form' => $form,
            'tutorEvaluation' => $tutorEvaluation,
            'studentEvaluation' => $studentEvaluation,
            'teamEvaluation' => $teamEvaluation,
            'supervisorEvaluation' => $supervisorEvaluation,
            'readOnly' => $isClosed,
            'backPath' => $stepIndex > 0 ? $this->generateUrl('app_ufa_alternance_period_suivi', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => $steps[$stepIndex - 1]]) : null,
            'stepLabels' => [
                $translator->trans('ufaAlternanceWizardStepComportementLabel'),
                $translator->trans('ufaAlternanceWizardStepCompetencesLabel'),
                $translator->trans('ufaAlternanceWizardStepSuiviRemarquesLabel'),
                $translator->trans('ufaAlternanceWizardStepSuiviClotureLabel'),
            ],
            'currentStepIndex' => $stepIndex + 1,
        ]);
    }

    // Single-alternance relance (34c) - GET renders the send panel content (loaded into a
    // Bootstrap modal on 34a), POST sends it. AJAX path (fetch, not a plain form submit) - CSRF
    // travels as the X-CSRF-Token header, per the header-vs-body distinction the 2026-07-28 UFA
    // CSRF audit flagged repeatedly on this exact surface.
    #[Route(path: '/ufa/alternances/{id}/relance', name: 'app_ufa_alternance_reminder', requirements: ['id' => '\d+'])]
    public function reminder(int $id, InternshipTutorLinkRepository $tutorLinkRepository, AlternancePeriodStatusResolver $statusResolver, InternshipReminderRepository $reminderRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $status = $statusResolver->resolveCurrentStep($tutorLink);
        $step = $this->reminderStepFor($status->step) ?? throw $this->createNotFoundException();

        return $this->render('ufa/alternance/_reminder_panel.html.twig', [
            'tutorLink' => $tutorLink,
            'status' => $status,
            'step' => $step,
            'reminders' => $reminderRepository->findAllForTutorLinkOrderedByMostRecent($tutorLink),
        ]);
    }

    #[Route(path: '/ufa/alternances/{id}/relance/send', name: 'app_ufa_alternance_reminder_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reminderSend(int $id, Request $request, InternshipTutorLinkRepository $tutorLinkRepository, AlternancePeriodStatusResolver $statusResolver, AlternanceReminderService $reminderService): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('ufa_alternance_reminder_send', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $status = $statusResolver->resolveCurrentStep($tutorLink);
        $step = $this->reminderStepFor($status->step) ?? throw $this->createNotFoundException();

        $ccRoles = $request->request->all('cc');
        $reminderService->sendSingle($tutorLink, $step, $status->period, array_values(array_intersect($ccRoles, ['tutor', 'supervisor'])), $this->currentUser());

        $this->addFlash('success', 'ufaAlternanceReminderSentFlashMessage');

        return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
    }

    // Relances groupées par période (26i) - cross-Program, generalizing the older
    // ProgramInternshipController::evaluationReminders()'s single-Program scope; picks a period
    // from ANY alternance Program, lists non-soumis tutor/student, bulk-sends via
    // AlternanceReminderService::sendBulkForPeriod().
    #[Route(path: '/ufa/relances', name: 'app_ufa_alternance_reminders')]
    public function reminders(Request $request, SchoolYearRepository $schoolYearRepository, ProgramRepository $programRepository, InternshipEvaluationPeriodRepository $periodRepository, InternshipTutorLinkRepository $tutorLinkRepository, AlternancePeriodStatusResolver $statusResolver): Response
    {
        $schoolYear = $schoolYearRepository->findCurrentOrMostRecent();
        $periods = [];
        foreach (null !== $schoolYear ? $programRepository->findAlternanceForSchoolYear($schoolYear) : [] as $program) {
            foreach ($periodRepository->findAllActiveForProgram($program) as $period) {
                $periods[] = $period;
            }
        }

        $selectedPeriodId = $request->query->getInt('period', 0);
        $selectedPeriod = null;
        foreach ($periods as $period) {
            if ($period->getId() === $selectedPeriodId) {
                $selectedPeriod = $period;
                break;
            }
        }

        $rows = [];
        if (null !== $selectedPeriod) {
            foreach ($tutorLinkRepository->findAllActiveForProgram($selectedPeriod->getProgram()) as $tutorLink) {
                $status = $statusResolver->resolveStepForPeriod($tutorLink, $selectedPeriod);
                if (\in_array($status->step, [AlternanceStepStatus::STEP_TUTOR, AlternanceStepStatus::STEP_STUDENT], true)) {
                    $rows[] = ['tutorLink' => $tutorLink, 'status' => $status, 'badge' => $statusResolver->badgeFor($status)];
                }
            }
        }

        return $this->render('ufa/alternance/reminders.html.twig', [
            'periods' => $periods,
            'selectedPeriod' => $selectedPeriod,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/ufa/relances/send', name: 'app_ufa_alternance_reminders_send', methods: ['POST'])]
    public function remindersSend(Request $request, InternshipEvaluationPeriodRepository $periodRepository, InternshipTutorLinkRepository $tutorLinkRepository, AlternanceReminderService $reminderService, TranslatorInterface $translator): Response
    {
        $period = $periodRepository->find($request->request->getInt('period')) ?? throw $this->createNotFoundException();
        $this->assertValidFormToken('ufa_alternance_reminders_send', $request);

        $selectedIds = array_map('intval', $request->request->all('tutorLinkIds'));
        $tutorLinks = array_values(array_filter(
            $tutorLinkRepository->findAllActiveForProgram($period->getProgram()),
            static fn (InternshipTutorLink $tutorLink): bool => \in_array($tutorLink->getId(), $selectedIds, true),
        ));

        $sent = $reminderService->sendBulkForPeriod($period, $tutorLinks, $this->currentUser());

        $this->addFlash('success', $translator->trans('ufaAlternanceRemindersBulkSentFlashMessage', ['%count%' => $sent]));

        return $this->redirectToRoute('app_ufa_alternance_reminders', ['period' => $period->getId()]);
    }

    private function reminderStepFor(string $statusStep): ?AlternanceReminderStep
    {
        return match ($statusStep) {
            AlternanceStepStatus::STEP_ENGAGEMENT_TUTOR => AlternanceReminderStep::EngagementTutor,
            AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT => AlternanceReminderStep::EngagementStudent,
            AlternanceStepStatus::STEP_TUTOR => AlternanceReminderStep::Tutor,
            AlternanceStepStatus::STEP_STUDENT => AlternanceReminderStep::Student,
            AlternanceStepStatus::STEP_SUPERVISOR => AlternanceReminderStep::Supervisor,
            default => null,
        };
    }

    // Livret reader (26d): left TOC card (static, matching the booklet's own section anchors) +
    // an iframe pointing at livretFrame() below - deliberately not real pagination/thumbnails/
    // zoom, see the feature's plan doc, architecture call 6, for why: this document's real
    // deliverable is the Gotenberg PDF export (already solved), a from-scratch paginated reader
    // would be disproportionate effort for a secondary in-browser view of the same content.
    #[Route(path: '/ufa/alternances/{id}/livret', name: 'app_ufa_alternance_livret', requirements: ['id' => '\d+'])]
    public function livret(int $id, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $periodRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();

        return $this->render('ufa/alternance/livret.html.twig', [
            'tutorLink' => $tutorLink,
            'periods' => $periodRepository->findAllActiveForProgram($tutorLink->getProgram()),
        ]);
    }

    // Standalone, unwrapped booklet render for the reader's <iframe src="..."> - same template as
    // the PDF export and the tutor/student's own "view" routes, just with assetBaseUrl left null
    // so asset() resolves relative to the browser (the Gotenberg-bound render below overrides it
    // to 'http://php' since that container has no browser origin - see
    // InternshipBookletPdfExporter's own docblock).
    #[Route(path: '/ufa/alternances/{id}/livret/frame', name: 'app_ufa_alternance_livret_frame', requirements: ['id' => '\d+'])]
    public function livretFrame(int $id, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletBuilder $bookletBuilder): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();

        return $this->render('internship/booklet.html.twig', $bookletBuilder->build($tutorLink));
    }

    #[Route(path: '/ufa/alternances/{id}/livret/pdf', name: 'app_ufa_alternance_livret_pdf', requirements: ['id' => '\d+'])]
    public function livretPdf(int $id, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletPdfExporter $exporter): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();

        try {
            $pdf = $exporter->export($tutorLink, $this->renderView(...));
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'internshipBookletPdfExportFailedFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_livret', ['id' => $tutorLink->getId()]);
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('livret-alternant-%s.pdf', $tutorLink->getStudent()?->getUsername())),
        ]);
    }

    // Backs the "Alternant" tom-select ajax field (32a) - only students already enrolled in one
    // of the current school year's alternance Programs are eligible, matching the spec's "jamais
    // créé depuis l'UFA".
    #[Route(path: '/ufa/alternances/eleve-search', name: 'app_ufa_alternance_student_search')]
    public function studentSearch(Request $request, SchoolYearRepository $schoolYearRepository, ProgramRepository $programRepository): JsonResponse
    {
        $schoolYear = $schoolYearRepository->findCurrentOrMostRecent();
        $limit = 20;
        $query = mb_strtolower((string) $request->query->get('q', ''));

        $candidates = [];
        foreach (null !== $schoolYear ? $programRepository->findAlternanceForSchoolYear($schoolYear) : [] as $program) {
            foreach ($program->getStudents() as $student) {
                if ('' === $query || str_contains(mb_strtolower($student->getDisplayName() ?? $student->getUsername()), $query)) {
                    // The formation name is folded into the option text (rather than a separate
                    // field) so section 1's read-only "Formation (UFA)" display can be filled
                    // client-side straight from the picked tom-select option, with no second
                    // round-trip - see new.html.twig.
                    $candidates[$student->getId()] = [$student, $program];
                }
            }
        }
        $candidates = array_values($candidates);

        return $this->json([
            'results' => array_map(static fn (array $pair): array => [
                'id' => $pair[0]->getId(),
                'text' => \sprintf('%s — %s', $pair[0]->getDisplayName() ?? $pair[0]->getUsername(), $pair[1]->getDisplayShortName()),
                'formation' => $pair[1]->getDisplayShortName(),
            ], \array_slice($candidates, 0, $limit)),
            'pagination' => ['more' => \count($candidates) > $limit],
        ]);
    }

    // Backs the "Rechercher un tuteur" tom-select ajax field (32a/32b) - see
    // InternshipTutorLinkRepository::searchDistinctTutors(), each result's "id" is an
    // InternshipTutorLink id (the closest thing to a Tutor id this app has, see the feature's
    // plan doc, architecture call 0.1), resolved back into tutor fields by
    // InternshipAlternanceType's SUBMIT listener.
    #[Route(path: '/ufa/alternances/tuteur-search', name: 'app_ufa_alternance_tutor_search')]
    public function tutorSearch(Request $request, InternshipTutorLinkRepository $tutorLinkRepository): JsonResponse
    {
        $limit = 20;
        $results = $tutorLinkRepository->searchDistinctTutors((string) $request->query->get('q', ''), $limit + 1);

        return $this->json([
            'results' => array_map(static fn (InternshipTutorLink $link): array => [
                'id' => $link->getId(),
                'text' => \sprintf('%s %s — %s', $link->getTutorFirstName(), $link->getTutorLastName(), $link->getEnterprise()?->getName() ?? $link->getTutorEmail()),
                // Lets alternance_tutor_picker_controller.js pre-select section 3's Entreprise
                // dropdown client-side ("l'entreprise est reprise automatiquement", 32a) - the
                // server-side SUBMIT-listener carry stays as the authoritative fallback.
                'enterpriseId' => $link->getEnterprise()?->getId(),
            ], \array_slice($results, 0, $limit)),
            'pagination' => ['more' => \count($results) > $limit],
        ]);
    }

    // Plain <form method="post"> submit from the dashboard row (no JS confirm dialog - this app
    // has no generic standalone confirm+fetch controller outside DataTables-driven lists, and
    // this table is plain server-rendered, not a DataTable) - CSRF travels as the body field.
    #[Route(path: '/ufa/alternances/{id}/deactivate', name: 'app_ufa_alternance_deactivate', methods: ['POST'])]
    public function deactivate(int $id, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $this->assertValidFormToken('ufa_alternance_deactivate', $request);

        $tutorLink->setInactiveDate(new \DateTimeImmutable());
        $tutorLink->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        $this->addFlash('success', 'ufaAlternanceDeactivatedFlashMessage');

        return $this->redirectToRoute('app_ufa', $request->query->all());
    }

    #[Route(path: '/ufa/alternances/{id}/reactivate', name: 'app_ufa_alternance_reactivate', methods: ['POST'])]
    public function reactivate(int $id, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $this->assertValidFormToken('ufa_alternance_deactivate', $request);

        $tutorLink->setInactiveDate(null);
        $tutorLink->setInactivatedBy(null);
        $entityManager->flush();

        $this->addFlash('success', 'ufaAlternanceReactivatedFlashMessage');

        return $this->redirectToRoute('app_ufa', $request->query->all());
    }

    // Program-scoped variant for the edit form (the picked student must stay within the
    // alternance's own Program) - same shape as ProgramInternshipController::resolveProgramStudent().
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

    /** @param list<Program> $alternancePrograms */
    private function resolveAlternanceStudent(array $alternancePrograms, mixed $studentId): array
    {
        if (!is_numeric($studentId)) {
            return [null, null];
        }

        foreach ($alternancePrograms as $program) {
            foreach ($program->getStudents() as $student) {
                if ($student->getId() === (int) $studentId) {
                    return [$student, $program];
                }
            }
        }

        return [null, null];
    }

    /** @param list<Program> $formations */
    private function findFormationOrNotFound(array $formations, int $id): Program
    {
        foreach ($formations as $formation) {
            if ($formation->getId() === $id) {
                return $formation;
            }
        }

        throw $this->createNotFoundException();
    }

    /** @param list<SchoolYear> $schoolYears */
    private function findSchoolYearOrNotFound(array $schoolYears, int $id): SchoolYear
    {
        foreach ($schoolYears as $schoolYear) {
            if ($schoolYear->getId() === $id) {
                return $schoolYear;
            }
        }

        throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    // For plain <form method="post"> submissions - the token travels as a body field (name="_token").
    private function assertValidFormToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
