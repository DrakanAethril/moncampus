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
use App\Service\StudentAlternanceProgramResolver;
use App\Service\TicketStatusFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Per-profile home dashboards (design/design_handoff_dashboards): étudiant (etu-a/b/c/e),
 * enseignant (ens-a/b), staff (staff-a/b) and the admin "Mes rôles" toggle (adm-a/b/c). All the
 * role logic lives here so the templates only render the datasets they're given; ROLE_TUTOR
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
        private readonly StudentAlternanceProgramResolver $alternanceProgramResolver,
    ) {
    }

    #[Route(path: '/', name: 'app_home')]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): Response
    {
        $user = $this->currentUser();

        // ROLE_TUTOR (entreprise tutors) have their own single-tab shell and landing page.
        if (\in_array('ROLE_TUTOR', $user->getRoles(), true)) {
            return $this->redirectToRoute('app_internship_tutor_home');
        }

        $today = new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable();
        $isStaff = $this->structureAccessChecker->isStaff();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $viewData = [
            'user' => $user,
            'today' => $today,
            'now' => $now,
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
                'teacher' => $viewData['teacher'] = $this->buildTeacherData($user, $today, $now),
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
            $viewData['teacher'] = $this->buildTeacherData($user, $today, $now);
        }

        if ($this->isGranted('ROLE_STUDENT')) {
            $viewData['student'] = $this->buildStudentData($user, $today);
        }

        return $this->render('home/index.html.twig', $viewData);
    }

    /**
     * The staff dashboard's timetable card on its own, for the day asked for by its ‹ / › arrows
     * and its date picker.
     *
     * Served into the <turbo-frame> the card is wrapped in, so walking through the days repaints
     * the matrix alone and leaves the relances banner and the events row untouched. That is also
     * why it renders the same partial the dashboard includes rather than a second copy of it.
     */
    #[Route(path: '/tableau-de-bord/emploi-du-temps', name: 'app_home_staff_timetable')]
    #[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
    public function staffTimetable(Request $request): Response
    {
        $today = new \DateTimeImmutable('today');

        return $this->render('home/_staff_timetable.html.twig', [
            'timetable' => $this->buildStaffTimetable($today, $this->readDay($request->query->get('day'))),
        ]);
    }

    // 'Y-m-d' only, and an unparseable value falls back to the default day rather than 500-ing on
    // a hand-edited query string.
    private function readDay(mixed $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }

    /**
     * Top 4 upcoming agenda events the user may see, with the etu-a badge states: "Convoqué"
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
        ], \array_slice($events, 0, 4));
    }

    private function buildStudentData(User $student, \DateTimeImmutable $today): array
    {
        // The dashboard shows one world or the other, never both: test Programs stay out of a
        // real account's dashboard (same rule as the teacher/staff data and the timetable), and are
        // all a test account sees there.
        $programs = array_values(array_filter(
            $this->programRepository->findAllActiveForStudent($student),
            static fn (Program $program): bool => $program->isTestProgram() === $student->isTestUser(),
        ));

        $programMeta = [];
        foreach ($programs as $program) {
            $programMeta[$program->getId()] = [
                'color' => $this->cohortColor($program->getCohort()),
                'isAlternance' => $program->isInternshipManagementEnabled(),
            ];
        }

        // Same rule as the teacher and staff cards: today, or - when today has no session at all -
        // the next day that does, so a free day shows the next lesson day instead of nothing.
        // $programs is already scoped to this student's side of the test fence, so a test student
        // looks for its next lesson day among test formations only.
        $day = $today;
        $daySessions = $this->sessionsForProgramsOnDay($programs, $today);

        if ([] === $daySessions) {
            $nextDay = $this->lessonSessionRepository->findNextSessionDayForPrograms($programs, $today);
            if (null !== $nextDay) {
                $day = $nextDay;
                $daySessions = $this->sessionsForProgramsOnDay($programs, $nextDay);
            }
        }

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
            'day' => $day,
            'dayIsToday' => $day->format('Y-m-d') === $today->format('Y-m-d'),
            'daySessions' => $daySessions,
            'assignments' => $assignments,
            'alternance' => $alternance,
            'banner' => $this->buildStudentBanner($assignments, $alternance),
        ];
    }

    /**
     * Every session these Programs hold on one day, in start-hour order.
     *
     * @param list<Program> $programs
     *
     * @return list<LessonSession>
     */
    private function sessionsForProgramsOnDay(array $programs, \DateTimeImmutable $day): array
    {
        $sessions = [];
        foreach ($programs as $program) {
            foreach ($this->lessonSessionRepository->findForProgramBetween($program, $day, $day) as $session) {
                $sessions[] = $session;
            }
        }

        usort($sessions, static fn (LessonSession $a, LessonSession $b): int => $a->getStartHour() <=> $b->getStartHour());

        return $sessions;
    }

    /** @param list<Program> $programs */
    private function buildStudentAlternance(User $student, array $programs): ?array
    {
        // Same "is this student an alternant" rule as the navbar tab and the page it opens - the
        // card used to settle for "the Program runs alternance", which is true for a classmate on
        // the classic track too.
        $alternanceProgram = $this->alternanceProgramResolver->resolve($student);

        foreach ($programs as $program) {
            if ($program !== $alternanceProgram) {
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

    private function buildTeacherData(User $teacher, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
        // findAllForTeacher() is shared with the séquence/quiz instantiation pickers, which must
        // keep offering test Programs to a real account - the dashboard's strict one-world-only
        // rule is applied here instead.
        $programs = array_values(array_filter(
            $this->programRepository->findAllForTeacher($teacher),
            static fn (Program $program): bool => $program->isTestProgram() === $teacher->isTestUser(),
        ));

        // Day column: today, or - when today has no session at all - the next day that does, so a
        // free day shows the next teaching day instead of nothing. "Aucun cours aujourd'hui" is
        // then only shown when there is genuinely nothing left ahead either.
        $day = $today;
        $daySessions = $this->lessonSessionRepository->findForTeacherOnDayMatchingTestMode($teacher, $today);
        if ([] === $daySessions) {
            $nextDay = $this->lessonSessionRepository->findNextSessionDayForTeacher($teacher, $today);
            if (null !== $nextDay) {
                $day = $nextDay;
                $daySessions = $this->lessonSessionRepository->findForTeacherOnDayMatchingTestMode($teacher, $nextDay);
            }
        }

        // "Mes prochaines séances par classe": one row per class that still has a session ahead,
        // carrying that session's matière/date/salle. A class with nothing left ahead drops out.
        $nextSessionByProgramId = $this->lessonSessionRepository->findNextSessionPerProgramForTeacher($teacher, $today, $now);

        $classes = [];
        foreach ($programs as $program) {
            $nextSession = $nextSessionByProgramId[$program->getId()] ?? null;
            if (null === $nextSession) {
                continue;
            }

            $classes[] = [
                'program' => $program,
                'studentCount' => $program->getStudents()->count(),
                'nextSession' => $nextSession,
            ];
        }

        // Soonest session first - the card reads as "what comes next", not as a class roster, so
        // $programs' own alphabetical order is the wrong axis here. Sorted on a "date + heure +
        // nom" string: startHour breaks ties within a day, the Program name only settles two
        // sessions starting at the same minute.
        $sortKey = static fn (array $class): string => $class['nextSession']->getDay()->format('Y-m-d')
            .$class['nextSession']->getStartHour()->format('H:i:s')
            .$class['program']->getShortName();
        usort($classes, static fn (array $a, array $b): int => $sortKey($a) <=> $sortKey($b));

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
            'day' => $day,
            'dayIsToday' => $day->format('Y-m-d') === $today->format('Y-m-d'),
            'daySessions' => $daySessions,
            'classes' => $classes,
            'pendingTeam' => $pendingTeam,
        ];
    }

    private function buildStaffData(\DateTimeImmutable $today): array
    {
        return [
            'banner' => $this->buildStaffBanner($today),
            'timetable' => $this->buildStaffTimetable($today, null),
        ];
    }

    /**
     * The staff dashboard's all-classes day matrix, for one day.
     *
     * $requestedDay is the day the arrows/date picker asked for and is honoured as-is, empty or
     * not: having explicitly navigated to a Wednesday, a teacher is told it is empty rather than
     * silently bounced somewhere else. Only the DEFAULT day (null) keeps the original "today, or
     * the next day that actually has a session" fallback, so landing on the dashboard on a Sunday
     * still shows something.
     */
    private function buildStaffTimetable(\DateTimeImmutable $today, ?\DateTimeImmutable $requestedDay): array
    {
        $day = $requestedDay ?? $today;
        $testMode = $this->structureAccessChecker->isTestViewer();
        $sessions = $this->lessonSessionRepository->findAllForDay($day, $testMode);

        if (null === $requestedDay && [] === $sessions) {
            $nextDay = $this->lessonSessionRepository->findNextSessionDayForAnyProgram($today, $testMode);
            if (null !== $nextDay) {
                $day = $nextDay;
                $sessions = $this->lessonSessionRepository->findAllForDay($nextDay, $testMode);
            }
        }

        // Legend and colors are per Program, not per Track: several Programs of one Track (SIO1 /
        // SIO2) sit on their own matrix rows, so a Track-level legend gave them one shared entry
        // painted with whichever cohort happened to be read first.
        $sessionsByProgramId = [];
        $legend = [];
        foreach ($sessions as $session) {
            $program = $session->getProgram();
            $programId = $program->getId();

            $sessionsByProgramId[$programId][] = $session;
            $legend[$programId] ??= ['program' => $program, 'color' => $this->cohortColor($program->getCohort())];
        }

        $axis = $this->buildStaffMatrixAxis($sessions);

        $rows = [];
        foreach ($sessionsByProgramId as $programId => $programSessions) {
            $rows[] = [
                'program' => $legend[$programId]['program'],
                'color' => $legend[$programId]['color'],
                'blocks' => $this->buildStaffMatrixBlocks($programSessions, $axis),
            ];
        }

        return [
            'day' => $day,
            'dayIsToday' => $day->format('Y-m-d') === $today->format('Y-m-d'),
            // Null on either side disables that arrow rather than hiding it, so the control keeps
            // its width and the date stops jumping sideways as you walk through the year.
            'previousDay' => $this->lessonSessionRepository->findPreviousSessionDayForAnyProgram($day, $testMode),
            'nextDay' => $this->lessonSessionRepository->findNextSessionDayForAnyProgram($day, $testMode),
            'axis' => $axis,
            'rows' => $rows,
            'legend' => array_values($legend),
        ];
    }

    /**
     * The matrix' time axis: whole hours spanning the day's earliest start to its latest end.
     * Blocks are then placed and sized as a percentage of that span, which is what makes a
     * session's duration readable instead of the old one-column-per-start-time grid, where a 1h
     * and a 4h session drew the same box.
     *
     * @param list<LessonSession> $sessions
     *
     * @return array{startMinutes: int, spanMinutes: int, hourCount: int, hours: list<array{label: string, offset: float}>}
     */
    private function buildStaffMatrixAxis(array $sessions): array
    {
        $earliest = null;
        $latest = null;
        foreach ($sessions as $session) {
            $start = $this->minutesOfDay($session->getStartHour());
            // Guards a session whose end is missing/at or before its start from collapsing to a
            // zero-width block (and from dragging the axis backwards).
            $end = max($start + 15, $this->minutesOfDay($session->getEndHour()));

            $earliest = null === $earliest ? $start : min($earliest, $start);
            $latest = null === $latest ? $end : max($latest, $end);
        }

        // Whole hours out, so every tick is on the hour and the gridlines stay evenly spaced.
        $startMinutes = intdiv($earliest ?? 480, 60) * 60;
        $endMinutes = (int) (ceil(($latest ?? 540) / 60) * 60);
        $spanMinutes = max(60, $endMinutes - $startMinutes);

        $hours = [];
        for ($minute = $startMinutes; $minute <= $startMinutes + $spanMinutes; $minute += 60) {
            $hours[] = [
                'label' => \sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60),
                'offset' => round(100 * ($minute - $startMinutes) / $spanMinutes, 3),
            ];
        }

        return [
            'startMinutes' => $startMinutes,
            'spanMinutes' => $spanMinutes,
            'hourCount' => intdiv($spanMinutes, 60),
            'hours' => $hours,
        ];
    }

    /**
     * One Program's sessions as non-overlapping blocks on the axis. Sessions that overlap (a same
     * start, or one running into the next) are merged into a single block covering all of them -
     * they would otherwise be absolutely positioned on top of each other. The merged block is the
     * one carrying "+N" and the clickable detail modal.
     *
     * @param list<LessonSession>                                                                 $sessions
     * @param array{startMinutes: int, spanMinutes: int, hourCount: int, hours: list<array{label: string, offset: float}>} $axis
     */
    private function buildStaffMatrixBlocks(array $sessions, array $axis): array
    {
        usort($sessions, static fn (LessonSession $a, LessonSession $b): int => [$a->getStartHour(), $a->getEndHour()] <=> [$b->getStartHour(), $b->getEndHour()]);

        $blocks = [];
        foreach ($sessions as $session) {
            $start = $this->minutesOfDay($session->getStartHour());
            $end = max($start + 15, $this->minutesOfDay($session->getEndHour()));
            $last = array_key_last($blocks);

            if (null !== $last && $start < $blocks[$last]['endMinutes']) {
                $blocks[$last]['endMinutes'] = max($blocks[$last]['endMinutes'], $end);
                $blocks[$last]['sessions'][] = $session;

                continue;
            }

            $blocks[] = ['startMinutes' => $start, 'endMinutes' => $end, 'sessions' => [$session]];
        }

        return array_map(static fn (array $block): array => [
            'offset' => round(100 * ($block['startMinutes'] - $axis['startMinutes']) / $axis['spanMinutes'], 3),
            'width' => round(100 * ($block['endMinutes'] - $block['startMinutes']) / $axis['spanMinutes'], 3),
            'startLabel' => \sprintf('%02d:%02d', intdiv($block['startMinutes'], 60), $block['startMinutes'] % 60),
            'endLabel' => \sprintf('%02d:%02d', intdiv($block['endMinutes'], 60), $block['endMinutes'] % 60),
            'sessions' => $block['sessions'],
        ], $blocks);
    }

    private function minutesOfDay(\DateTimeImmutable $time): int
    {
        return 60 * (int) $time->format('G') + (int) $time->format('i');
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
