<?php

namespace App\Controller\Ufa;

use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Form\InternshipAlternanceType;
use App\Form\InternshipTutorLinkType;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipReminderRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\UserRepository;
use App\Service\AlternanceEngagementService;
use App\Service\AlternancePeriodStatusResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le dossier d'alternance lui-même : création, modification, fiche, (dés)activation, et les recherches d'étudiant/tuteur qui alimentent ses champs.
 *
 * Split out of the former UfaAlternanceController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_TEACHER")'))]
class AlternanceController extends AbstractController
{
    use UfaAlternanceTrait;

    #[Route(path: '/ufa/alternances/new', name: 'app_ufa_alternance_new')]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function createAlternance(Request $request, EntityManagerInterface $entityManager, SchoolYearRepository $schoolYearRepository, ProgramRepository $programRepository, AlternanceEngagementService $engagementService): Response
    {
        $schoolYear = $schoolYearRepository->findCurrentOrMostRecent() ?? throw $this->createNotFoundException();
        $alternancePrograms = $programRepository->findAlternanceForSchoolYear($schoolYear, false, $this->currentUser());

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

        // $tutor (picked or provisioned) and $enterprise are both resolved inside the form's own
        // SUBMIT listener, before validation - see InternshipAlternanceType.
        if ($form->isSubmitted() && $form->isValid() && null !== $student) {
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
    // student resolution as Program\InternshipTutorController::tutorLinkForm() and for the same reason
    // (Assert\NotNull on $student).
    #[Route(path: '/ufa/alternances/{id}/edit', name: 'app_ufa_alternance_edit', requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function editAlternance(int $id, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $program = $tutorLink->getProgram();

        if ($request->isMethod('POST')) {
            $tutorLink->setStudent($this->resolveProgramStudent($program, $request->request->get('student')));
        }

        $form = $this->createForm(InternshipTutorLinkType::class, $tutorLink, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
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

    // Backs the "Alternant" tom-select ajax field (32a) - only students already enrolled in one
    // of the current school year's alternance Programs are eligible, matching the spec's "jamais
    // créé depuis l'UFA".
    #[Route(path: '/ufa/alternances/student-search', name: 'app_ufa_alternance_student_search')]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function studentSearch(Request $request, SchoolYearRepository $schoolYearRepository, ProgramRepository $programRepository): JsonResponse
    {
        $schoolYear = $schoolYearRepository->findCurrentOrMostRecent();
        $limit = 20;
        $query = mb_strtolower((string) $request->query->get('q', ''));

        $candidates = [];
        foreach (null !== $schoolYear ? $programRepository->findAlternanceForSchoolYear($schoolYear, false, $this->currentUser()) : [] as $program) {
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
    // UserRepository::searchTutors(), which searches tutor ACCOUNTS rather than the alternances
    // they already hold: a tutor created straight from Annuaire > Utilisateurs (the usual way a
    // test tutor is set up before any alternance exists) holds no link, and the previous
    // link-based search left exactly those accounts unpickable here.
    #[Route(path: '/ufa/alternances/tutor-search', name: 'app_ufa_alternance_tutor_search')]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function tutorSearch(Request $request, UserRepository $userRepository, InternshipTutorLinkRepository $tutorLinkRepository): JsonResponse
    {
        $limit = 20;
        $tutors = $userRepository->searchTutors((string) $request->query->get('q', ''), $limit + 1, $this->currentUser());
        $page = \array_slice($tutors, 0, $limit);
        // The entreprise beside each name no longer rides along with the row - looked up for the
        // whole page at once instead (see findMostRecentEnterprisesForTutors()).
        $enterprises = $tutorLinkRepository->findMostRecentEnterprisesForTutors($page);

        return $this->json([
            'results' => array_map(static function (User $tutor) use ($enterprises): array {
                $enterprise = $enterprises[$tutor->getId()] ?? null;

                return [
                    // A tutor User id - the picker attaches the alternance to that account
                    // directly (see App\Service\InternshipTutorFormResolver).
                    'id' => $tutor->getId(),
                    'text' => \sprintf('%s %s — %s', $tutor->getFirstname(), $tutor->getLastname(), $enterprise?->getName() ?? $tutor->getContactEmail()),
                    // Lets alternance_tutor_picker_controller.js pre-select section 3's Entreprise
                    // dropdown client-side ("l'entreprise est reprise automatiquement", 32a) - the
                    // server-side SUBMIT-listener carry stays as the authoritative fallback.
                    'enterpriseId' => $enterprise?->getId(),
                ];
            }, $page),
            'pagination' => ['more' => \count($tutors) > $limit],
        ]);
    }

    // Plain <form method="post"> submit from the dashboard row (no JS confirm dialog - this app
    // has no generic standalone confirm+fetch controller outside DataTables-driven lists, and
    // this table is plain server-rendered, not a DataTable) - CSRF travels as the body field.
    #[Route(path: '/ufa/alternances/{id}/deactivate', name: 'app_ufa_alternance_deactivate', methods: ['POST'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
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
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
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
    // alternance's own Program) - same shape as Program\ProgramInternshipTrait::resolveProgramStudent().
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
}
