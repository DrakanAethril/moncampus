<?php

namespace App\Controller;

use App\Entity\AgendaEvent;
use App\Entity\Cohort;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\AgendaEventRepository;
use App\Repository\AssignmentRepository;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Repository\TicketRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\AudienceTargetableVoter;
use App\Service\AlternancePeriodWizardService;
use App\Service\AssignmentAudienceResolver;
use App\Service\NameColorGenerator;
use App\Service\TicketStatusFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Per-profile home dashboards (design/design_handoff_dashboards): étudiant (etu-a/b/c/e),
 * enseignant (ens-a/b), staff (staff-a/b) and the admin "Mes rôles" toggle (adm-a/b/c). All the
 * role logic lives here so the templates only render the datasets they're given; ROLE_EXTERNAL
 * tutors get their own landing in InternshipTutorEvaluationController (35a-e).
 */
class HomeController extends AbstractController
{
    private const string ADMIN_ROLE_SESSION_KEY = 'dashboard_active_role';

    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly LessonSessionRepository $lessonSessionRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly AssignmentAudienceResolver $assignmentAudienceResolver,
        private readonly AgendaEventRepository $agendaEventRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly TicketStatusFormatter $ticketStatusFormatter,
        private readonly InternshipTutorLinkRepository $tutorLinkRepository,
        private readonly InternshipEvaluationPeriodRepository $evaluationPeriodRepository,
        private readonly InternshipLivretEngagementRepository $engagementRepository,
        private readonly InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private readonly AlternancePeriodWizardService $wizardService,
        private readonly StructureAccessChecker $structureAccessChecker,
        private readonly NameColorGenerator $nameColorGenerator,
    ) {
    }

    #[Route(path: '/', name: 'app_home')]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): Response
    {
        $user = $this->currentUser();

        // ROLE_EXTERNAL (entreprise tutors) have their own single-tab shell and landing page.
        if (\in_array('ROLE_EXTERNAL', $user->getRoles(), true)) {
            return $this->redirectToRoute('app_internship_tutor_home');
        }

        $today = new \DateTimeImmutable('today');
        $isStaff = $this->structureAccessChecker->isStaff();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $viewData = [
            'user' => $user,
            'today' => $today,
            'now' => new \DateTimeImmutable(),
            'events' => $this->buildEvents($user),
        ];

        if ($isAdmin) {
            // "Mes rôles" toggle (adm-a/b/c): Enseignant · Staff · Administration, only the roles
            // actually held, switching the page content without changing account or navbar.
            $availableRoles = [];
            if ($this->isGranted('ROLE_TEACHER')) {
                $availableRoles[] = 'teacher';
            }
            $availableRoles[] = 'staff';
            $availableRoles[] = 'administration';

            $session = $request->getSession();
            $requested = $request->query->get('role', $session->get(self::ADMIN_ROLE_SESSION_KEY));
            $activeRole = \in_array($requested, $availableRoles, true) ? $requested : $availableRoles[0];
            $session->set(self::ADMIN_ROLE_SESSION_KEY, $activeRole);

            $viewData['admin'] = ['availableRoles' => $availableRoles, 'activeRole' => $activeRole];

            match ($activeRole) {
                'teacher' => $viewData['teacher'] = $this->buildTeacherData($user, $today),
                'staff' => $viewData['staff'] = $this->buildStaffData($today),
                default => $viewData['administration'] = $this->buildAdministrationData(),
            };

            return $this->render('home/index.html.twig', $viewData);
        }

        if ($isStaff) {
            $viewData['staff'] = $this->buildStaffData($today);

            return $this->render('home/index.html.twig', $viewData);
        }

        if ($this->isGranted('ROLE_TEACHER')) {
            $viewData['teacher'] = $this->buildTeacherData($user, $today);
        }

        if ($this->isGranted('ROLE_STUDENT')) {
            $viewData['student'] = $this->buildStudentData($user, $today);
        }

        return $this->render('home/index.html.twig', $viewData);
    }

    /**
     * Top 3 upcoming agenda events the user may see, with the etu-a badge states: "Convoqué"
     * when individually targeted, "Inscription ouverte" when the event carries a signup list.
     */
    private function buildEvents(User $user): array
    {
        $events = array_values(array_filter(
            $this->agendaEventRepository->findUpcoming(),
            fn (AgendaEvent $event): bool => $this->isGranted(AudienceTargetableVoter::VIEW, $event),
        ));

        return array_map(static fn (AgendaEvent $event): array => [
            'event' => $event,
            'invited' => $event->getManualRecipients()->contains($user),
            'signup' => null !== $event->getSignupList(),
        ], \array_slice($events, 0, 3));
    }

    private function buildStudentData(User $student, \DateTimeImmutable $today): array
    {
        $programs = $this->programRepository->findAllActiveForStudent($student);

        $programMeta = [];
        foreach ($programs as $program) {
            $programMeta[$program->getId()] = [
                'color' => $this->cohortColor($program->getCohort()),
                'isAlternance' => $program->isInternshipManagementEnabled(),
            ];
        }

        $daySessions = [];
        foreach ($programs as $program) {
            foreach ($this->lessonSessionRepository->findForProgramBetween($program, $today, $today) as $session) {
                $daySessions[] = $session;
            }
        }
        usort($daySessions, static fn (LessonSession $a, LessonSession $b): int => $a->getStartHour() <=> $b->getStartHour());

        // "Travail à réaliser": upcoming only (no notion of retard, §1.6), audience-filtered.
        $assignments = array_values(array_filter(
            $this->assignmentRepository->findUpcomingForPrograms($programs, $today),
            fn ($assignment): bool => $this->assignmentAudienceResolver->isInAudience($assignment, $student),
        ));
        $assignments = \array_slice($assignments, 0, 6);

        $alternance = $this->buildStudentAlternance($student, $programs);

        return [
            'programs' => $programs,
            'programMeta' => $programMeta,
            'daySessions' => $daySessions,
            'assignments' => $assignments,
            'alternance' => $alternance,
            'banner' => $this->buildStudentBanner($assignments, $alternance),
        ];
    }

    /** @param list<Program> $programs */
    private function buildStudentAlternance(User $student, array $programs): ?array
    {
        foreach ($programs as $program) {
            if (!$program->isInternshipManagementEnabled()) {
                continue;
            }

            $tutorLink = $this->tutorLinkRepository->findOneForStudentAndProgram($student, $program);
            if (null === $tutorLink) {
                return null;
            }

            // The card shows the period the student should care about right now: the first one
            // where it's their turn, else the last one with any activity.
            $currentPeriod = null;
            $currentEvaluations = null;
            $yourTurn = false;
            foreach ($this->evaluationPeriodRepository->findAllActiveForProgram($program) as $period) {
                $evaluations = $this->wizardService->evaluationsFor($tutorLink, $period);
                $studentSigned = null !== ($evaluations['studentEvaluation']?->getSignedAt());
                $closed = $this->wizardService->isPeriodClosed($tutorLink, $period);

                if (!$closed && !$studentSigned && $this->wizardService->isStudentStepOpen($tutorLink, $period)) {
                    $currentPeriod = $period;
                    $currentEvaluations = $evaluations;
                    $yourTurn = true;
                    break;
                }

                if (null !== ($evaluations['tutorEvaluation'] ?? null) || null === $currentPeriod) {
                    $currentPeriod = $period;
                    $currentEvaluations = $evaluations;
                }
            }

            return [
                'program' => $program,
                'tutorLink' => $tutorLink,
                'period' => $currentPeriod,
                'evaluations' => $currentEvaluations,
                'yourTurn' => $yourTurn,
                'engagement' => $this->engagementRepository->findOneForTutorLink($tutorLink),
            ];
        }

        return null;
    }

    /**
     * One banner, most urgent thing only (§1.2): alternance turn > engagement to sign > nearest
     * assignment to hand in. No banner at all when there is nothing to do (etu-c).
     */
    private function buildStudentBanner(array $assignments, ?array $alternance): ?array
    {
        if (null !== $alternance && $alternance['yourTurn']) {
            return [
                'type' => 'alternanceYourTurn',
                'program' => $alternance['program'],
                'period' => $alternance['period'],
                'tutorSignedAt' => $alternance['evaluations']['tutorEvaluation']?->getSignedAt(),
            ];
        }

        if (null !== $alternance && (null === $alternance['engagement'] || null === $alternance['engagement']->getSignedStudentAt())) {
            return ['type' => 'engagement', 'program' => $alternance['program']];
        }

        foreach ($assignments as $assignment) {
            if ($assignment->expectsSubmission()) {
                return ['type' => 'assignment', 'assignment' => $assignment];
            }
        }

        return null;
    }

    private function buildTeacherData(User $teacher, \DateTimeImmutable $today): array
    {
        $programs = $this->programRepository->findAllForTeacher($teacher);
        $topicsByProgramId = $this->lessonSessionRepository->findTopicNamesForTeacherByProgram($teacher);

        $nextSessionByProgramId = [];
        foreach ($this->lessonSessionRepository->findUpcomingForTeacher($teacher, $today, $today->modify('+60 days')) as $session) {
            $nextSessionByProgramId[$session->getProgram()->getId()] ??= $session;
        }

        $classes = array_map(static fn (Program $program): array => [
            'program' => $program,
            'topics' => $topicsByProgramId[$program->getId()] ?? [],
            'studentCount' => $program->getStudents()->count(),
            'nextSession' => $nextSessionByProgramId[$program->getId()] ?? null,
        ], $programs);

        // "Des livrets attendent vos remarques" (ens-b): each pending item carries its tutorLink
        // so the banner can deep-link into the équipe pédagogique wizard.
        $alternancePrograms = array_values(array_filter($programs, static fn (Program $program): bool => $program->isInternshipManagementEnabled()));
        $pendingTeam = [];
        foreach ($this->studentEvaluationRepository->findSignedAwaitingTeamForPrograms($alternancePrograms) as $evaluation) {
            $tutorLink = $this->tutorLinkRepository->findOneForStudentAndProgram($evaluation->getStudent(), $evaluation->getProgram());
            if (null !== $tutorLink) {
                $pendingTeam[] = ['evaluation' => $evaluation, 'tutorLink' => $tutorLink];
            }
        }

        return [
            'daySessions' => $this->lessonSessionRepository->findAllForTeacherBetween($teacher, $today, $today),
            'classes' => $classes,
            'pendingTeam' => $pendingTeam,
        ];
    }

    private function buildStaffData(\DateTimeImmutable $today): array
    {
        // Matrix "toutes les classes": one row per Program with sessions today, columns derived
        // from the start times actually present (not hardcoded créneaux).
        $sessions = $this->lessonSessionRepository->findAllForDay($today);

        $columnKeys = [];
        foreach ($sessions as $session) {
            $columnKeys[$session->getStartHour()->format('H:i')] = true;
        }
        $columns = array_keys($columnKeys);
        sort($columns);

        $rows = [];
        $legend = [];
        foreach ($sessions as $session) {
            $program = $session->getProgram();
            $programId = $program->getId();
            $cohort = $program->getCohort();
            $color = $this->cohortColor($cohort);
            $trackName = $cohort->getTrack()->getName();

            $rows[$programId] ??= [
                'program' => $program,
                'trackName' => $trackName,
                'color' => $color,
                'cells' => array_fill_keys($columns, []),
            ];
            $rows[$programId]['cells'][$session->getStartHour()->format('H:i')][] = $session;

            $legend[$trackName] ??= ['name' => $trackName, 'color' => $color];
        }

        return [
            'banner' => $this->buildStaffBanner($today),
            'matrix' => [
                'columns' => $columns,
                'rows' => array_values($rows),
                'legend' => array_values($legend),
                'classCount' => \count($rows),
                'formationCount' => \count($legend),
            ],
        ];
    }

    private function buildStaffBanner(\DateTimeImmutable $today): ?array
    {
        foreach ($this->evaluationPeriodRepository->findRunningAt($today) as $period) {
            $tutorsPending = $this->tutorLinkRepository->countPendingTutorForPeriod($period);
            $studentsPending = $this->tutorLinkRepository->countPendingStudentForPeriod($period);

            if ($tutorsPending + $studentsPending > 0) {
                return [
                    'period' => $period,
                    'tutorsPending' => $tutorsPending,
                    'studentsPending' => $studentsPending,
                    'total' => $tutorsPending + $studentsPending,
                ];
            }
        }

        return null;
    }

    private function buildAdministrationData(): array
    {
        $recentOpenTickets = $this->ticketRepository->findPage(0, 10, status: Ticket::STATUS_OPEN);

        return [
            'openTicketCount' => $this->ticketRepository->countAll(status: Ticket::STATUS_OPEN),
            'tickets' => array_map(
                fn (Ticket $ticket): array => [
                    'ticket' => $ticket,
                    'statusLabel' => $this->ticketStatusFormatter->statusLabel($ticket->getStatus()),
                    'statusClass' => $this->ticketStatusFormatter->statusCssClass($ticket->getStatus()),
                ],
                $recentOpenTickets,
            ),
        ];
    }

    private function cohortColor(Cohort $cohort): string
    {
        return $cohort->getColor() ?? $this->nameColorGenerator->generateHex($cohort->getName());
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
