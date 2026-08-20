<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Cohort;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\QuizLiveSession;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\StudentWorkState;
use App\Repository\AgendaEventRepository;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\PlatformActivityRepository;
use App\Repository\ProgramRepository;
use App\Repository\QuizLiveSessionRepository;
use App\Repository\RoomRepository;
use App\Repository\SurveyTargetRepository;
use App\Repository\TicketRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\AudienceTargetableVoter;
use App\Service\AlternancePeriodWizardService;
use App\Service\NameColorGenerator;
use App\Service\StudentAlternanceProgramResolver;
use App\Service\StudentWorkBoard;
use App\Service\StudentWorkRow;
use App\Service\TicketStatusFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-profile home dashboards (design/design_handoff_dashboards): étudiant (etu-a/b/c/e),
 * enseignant (ens-a/b), staff (staff-a/b) and the admin "Mes rôles" toggle (adm-a/b/c). All the
 * role logic lives here so the templates only render the datasets they're given; ROLE_TUTOR
 * tutors get their own landing in InternshipTutorEvaluationController (35a-e).
 */
class HomeController extends AbstractController
{
    // The two reading axes of the staff dashboard matrix, spelled out in the turbo-frame's URL
    // (?view=). Programs by default - that is this screen's historical reading.
    private const string TIMETABLE_VIEW_PROGRAMS = 'programs';
    private const string TIMETABLE_VIEW_ROOMS = 'rooms';

    private const string ADMIN_ROLE_SESSION_KEY = 'dashboard_active_role';

    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly LessonSessionRepository $lessonSessionRepository,
        private readonly StudentWorkBoard $studentWorkBoard,
        private readonly AgendaEventRepository $agendaEventRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly TicketStatusFormatter $ticketStatusFormatter,
        private readonly InternshipTutorLinkRepository $tutorLinkRepository,
        private readonly InternshipEvaluationPeriodRepository $evaluationPeriodRepository,
        private readonly InternshipLivretEngagementRepository $engagementRepository,
        private readonly PlatformActivityRepository $platformActivityRepository,
        private readonly QuizLiveSessionRepository $quizLiveSessionRepository,
        private readonly InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private readonly AlternancePeriodWizardService $wizardService,
        private readonly StructureAccessChecker $structureAccessChecker,
        private readonly NameColorGenerator $nameColorGenerator,
        private readonly RoomRepository $roomRepository,
        private readonly StudentAlternanceProgramResolver $alternanceProgramResolver,
        private readonly TranslatorInterface $translator,
        private readonly SurveyTargetRepository $surveyTargetRepository,
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
            // Every role, on purpose: a survey reaches a student through their travail à faire, but
            // teachers, staff and tutors have none at all - this card and « Mes sondages » are
            // their only door (design/validated/surveys.md §8).
            'pendingSurveys' => $this->surveyTargetRepository->findPendingForUser($user),
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
            $viewData['student'] = $this->buildStudentData($user, $today, $now);
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
    #[Route(path: '/dashboard/timetable', name: 'app_home_staff_timetable')]
    #[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
    public function staffTimetable(Request $request): Response
    {
        $today = new \DateTimeImmutable('today');

        return $this->render('home/_staff_timetable.html.twig', [
            'timetable' => $this->buildStaffTimetable($today, $this->readDay($request->query->get('day')), $this->readTimetableView($request)),
        ]);
    }

    // Anything other than "rooms" falls back to the per-program view: a value tinkered with in the
    // URL must not break the card.
    private function readTimetableView(Request $request): string
    {
        return self::TIMETABLE_VIEW_ROOMS === $request->query->get('view')
            ? self::TIMETABLE_VIEW_ROOMS
            : self::TIMETABLE_VIEW_PROGRAMS;
    }

    // 'Y-m-d' only, and an unparseable value falls back to the default day rather than 500-ing on
    // a hand-edited query string.
    private function readDay(mixed $value): ?\DateTimeImmutable
    {
        $value = \is_scalar($value) ? trim((string) $value) : '';
        if ('' === $value) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }

    /**
     * Top 4 upcoming agenda events the user may see, with the etu-a badge states: "Convoqué"
     * when individually targeted, "Inscription ouverte" when the event carries a signup list.
     *
     * Stops at the fourth visible event rather than filtering the whole list and slicing after.
     * findUpcoming() is unbounded and each verdict costs an audience resolution - AudienceResolver
     * materialises the entire recipient list of an event (every student and teacher of every
     * Program it targets) only to answer whether this one user is in it - so the discarded tail was
     * the expensive part of this method, not the four rows kept. array_filter() preserves order and
     * the slice took the first four, so walking in the same order and stopping at four yields the
     * same four events; this is a change of work done, not of what is shown.
     */
    private function buildEvents(User $user): array
    {
        $events = [];

        foreach ($this->agendaEventRepository->findUpcoming() as $event) {
            if (!$this->isGranted(AudienceTargetableVoter::VIEW, $event)) {
                continue;
            }

            $events[] = [
                'event' => $event,
                'invited' => $event->getManualRecipients()->contains($user),
                'signup' => null !== $event->getSignupList(),
            ];

            if (4 === \count($events)) {
                break;
            }
        }

        return $events;
    }

    private function buildStudentData(User $student, \DateTimeImmutable $today, \DateTimeImmutable $now): array
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

        // "Travail à réaliser": what is already late, then the seven days ahead, with a link to the
        // full screen for the rest (design_handoff_cahier_de_texte 4a). The card shows a horizon
        // rather than an arbitrary slice, which is what gives it meaning: what falls due this week.
        // Overdue lines are never cut by that horizon - they are behind it, and dropping them would
        // hide precisely what is most urgent; the full screen lists the same two groups, only
        // without the seven-day limit.
        //
        // Read from App\Service\StudentWorkBoard, exactly like the "Travail à faire" list, so that
        // the two never announce different things. What that buys, on top of the deadlines being
        // the same ones: only what is genuinely left to do is counted - a deposit already handed
        // in, a work set aside, a quiz already passed all drop out of the card and its banner
        // instead of standing there as "à rendre".
        $horizon = $today->modify('+7 days');
        // The dashboard's one-world rule again: $programs is already this student's side of the
        // test fence, and the board knows nothing of it.
        $programIds = array_flip(array_map(static fn (Program $program): int => $program->getId(), $programs));
        $workRows = array_values(array_filter(
            $this->studentWorkBoard->rows($this->studentWorkBoard->build($student, $now), $now),
            static fn (StudentWorkRow $row): bool => \in_array($row->state, [StudentWorkState::Late, StudentWorkState::Todo], true)
                && $row->dueDate <= $horizon
                && isset($programIds[$row->assignment()->getProgram()->getId()]),
        ));
        // Earliest first: the board hands back its lines assignment by assignment, and both the
        // card and the banner - which takes the first deposit it finds - read them as a countdown.
        // Overdue deadlines being the earliest of all, they head the card and the banner speaks of
        // the most overdue one first.
        usort($workRows, static fn (StudentWorkRow $a, StudentWorkRow $b): int => $a->dueDate <=> $b->dueDate);

        $alternance = $this->buildStudentAlternance($student, $programs);

        // A live contest is the one thing to do *right now*: the class is waiting on the other
        // side of the projector. $programs already sits on this student's side of the test fence,
        // and a student rarely has more than one formation - first active session found wins.
        $liveSession = null;
        foreach ($programs as $program) {
            $liveSession = $this->quizLiveSessionRepository->findActiveForProgram($program);
            if (null !== $liveSession) {
                break;
            }
        }

        return [
            'programs' => $programs,
            'programMeta' => $programMeta,
            'day' => $day,
            'dayIsToday' => $day->format('Y-m-d') === $today->format('Y-m-d'),
            'daySessions' => $daySessions,
            'workRows' => $workRows,
            'alternance' => $alternance,
            'banner' => $this->buildStudentBanner($workRows, $alternance, $liveSession),
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
                $studentSigned = null !== $evaluations['studentEvaluation']?->getSignedAt();
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
     * One banner, most urgent thing only (§1.2): live contest to join > alternance turn >
     * engagement to sign > nearest deposit to hand in. No banner at all when there is nothing to
     * do (etu-c). The contest comes first because it is the only entry that expires in minutes -
     * the class is playing while the student reads this page.
     *
     * @param list<StudentWorkRow> $workRows deadlines still to answer, earliest first
     */
    private function buildStudentBanner(array $workRows, ?array $alternance, ?QuizLiveSession $liveSession): ?array
    {
        if (null !== $liveSession) {
            return ['type' => 'quizLive', 'session' => $liveSession];
        }

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

        foreach ($workRows as $row) {
            if ($row->assignment()->expectsSubmission()) {
                // Overdue lines come first, so this is the deposit the student is furthest behind
                // on when there is one - said as such rather than as a deadline still ahead.
                return ['type' => 'assignment', 'row' => $row, 'late' => StudentWorkState::Late === $row->state];
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
            'engagementBanner' => $this->buildStaffEngagementBanner(),
            'timetable' => $this->buildStaffTimetable($today, null),
        ];
    }

    /**
     * "Mise à disposition du livret" waiting on the centre's own signature - the third and last
     * one, the one that opens the evaluation periods. Its own banner rather than a branch of
     * buildStaffBanner() below, because the two are not alternatives: an alternance can be
     * blocked at this gate while other alternances are mid-period, and both are staff's to act on.
     *
     * Null when there is nothing pending. $tutorLink carries the single pending one so the banner
     * can link straight at it; with several, it points at the UFA dashboard instead.
     */
    private function buildStaffEngagementBanner(): ?array
    {
        $pending = $this->engagementRepository->findAllPendingCenterSignature($this->structureAccessChecker->isTestViewer());

        if ([] === $pending) {
            return null;
        }

        return [
            'count' => \count($pending),
            'tutorLink' => 1 === \count($pending) ? $pending[0]->getTutorLink() : null,
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
    private function buildStaffTimetable(\DateTimeImmutable $today, ?\DateTimeImmutable $requestedDay, string $view = self::TIMETABLE_VIEW_PROGRAMS): array
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

        $axis = $this->buildStaffMatrixAxis($sessions);
        // A day without a single lesson: the card is left to its "no séance" message rather than
        // showing every room empty, which would say the same thing less clearly.
        $rows = [] === $sessions ? [] : (self::TIMETABLE_VIEW_ROOMS === $view
            ? $this->buildStaffMatrixRoomRows($sessions, $axis)
            : $this->buildStaffMatrixProgramRows($sessions, $axis));

        return [
            'day' => $day,
            'dayIsToday' => $day->format('Y-m-d') === $today->format('Y-m-d'),
            'view' => $view,
            // Null on either side disables that arrow rather than hiding it, so the control keeps
            // its width and the date stops jumping sideways as you walk through the year.
            'previousDay' => $this->lessonSessionRepository->findPreviousSessionDayForAnyProgram($day, $testMode),
            'nextDay' => $this->lessonSessionRepository->findNextSessionDayForAnyProgram($day, $testMode),
            'axis' => $axis,
            'rows' => $rows,
            // The legend mirrors the rows: same keys, same colors, and it doubles as a switch.
            'legend' => array_map(static fn (array $row): array => ['key' => $row['key'], 'label' => $row['label'], 'color' => $row['color']], $rows),
        ];
    }

    /**
     * Une ligne par formation - la vue par défaut.
     *
     * Legend and colors are per Program, not per Track: several Programs of one Track (SIO1 /
     * SIO2) sit on their own matrix rows, so a Track-level legend gave them one shared entry
     * painted with whichever cohort happened to be read first.
     *
     * @param list<LessonSession> $sessions
     * @param array<string, mixed> $axis
     *
     * @return list<array{key: string, label: string, color: string, blocks: list<array<string, mixed>>}>
     */
    private function buildStaffMatrixProgramRows(array $sessions, array $axis): array
    {
        $grouped = [];
        foreach ($sessions as $session) {
            $program = $session->getProgram();
            $grouped[$program->getId()] ??= ['program' => $program, 'sessions' => []];
            $grouped[$program->getId()]['sessions'][] = $session;
        }

        return array_values(array_map(fn (array $group): array => [
            'key' => 'p-'.$group['program']->getId(),
            'label' => $group['program']->getDisplayShortName(),
            'color' => $this->cohortColor($group['program']->getCohort()),
            'blocks' => $this->buildStaffMatrixBlocks($group['sessions'], $axis),
        ], $grouped));
    }

    /**
     * One row per room, in alphabetical order, each painted by the same color generator as the
     * cohorts without a color of their own (App\Service\NameColorGenerator) - Room carries none in
     * the database.
     *
     * Séances with no room are grouped under a dedicated row, placed last: leaving them aside would
     * make lessons disappear from the screen without saying so.
     *
     * @param list<LessonSession> $sessions
     * @param array<string, mixed> $axis
     *
     * @return list<array{key: string, label: string, color: string, blocks: list<array<string, mixed>>}>
     */
    private function buildStaffMatrixRoomRows(array $sessions, array $axis): array
    {
        // Every active room, occupied or not: an empty row reads at a glance as a room free for the
        // day, which is the intended use. The alphabetical order comes from the repository, so there
        // is nothing to sort here.
        $grouped = [];
        foreach ($this->roomRepository->findAllActiveOrderedByName() as $room) {
            $grouped['r-'.$room->getId()] = [
                'key' => 'r-'.$room->getId(),
                'label' => $room->getName(),
                'color' => $this->nameColorGenerator->generateHex($room->getName()),
                'sessions' => [],
            ];
        }

        $unassigned = [];
        foreach ($sessions as $session) {
            $room = $session->getClassRoom();

            if (null === $room) {
                $unassigned[] = $session;

                continue;
            }

            // A room deactivated since the lesson was scheduled is not in the list above: it is
            // added anyway, the occupancy being real.
            $grouped['r-'.$room->getId()] ??= [
                'key' => 'r-'.$room->getId(),
                'label' => $room->getName(),
                'color' => $this->nameColorGenerator->generateHex($room->getName()),
                'sessions' => [],
            ];
            $grouped['r-'.$room->getId()]['sessions'][] = $session;
        }

        // Séances with no room bring up the rear, and only if there are any - unlike rooms, an empty
        // "Sans salle" row would say nothing.
        if ([] !== $unassigned) {
            $grouped['r-none'] = [
                'key' => 'r-none',
                'label' => $this->translator->trans('homeStaffMatrixNoRoomLabel'),
                'color' => '#9aa4b2',
                'sessions' => $unassigned,
            ];
        }

        return array_values(array_map(fn (array $group): array => [
            'key' => $group['key'],
            'label' => $group['label'],
            'color' => $group['color'],
            'blocks' => $this->buildStaffMatrixBlocks($group['sessions'], $axis),
        ], $grouped));
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
            'activities' => $this->platformActivityRepository->findLatest(10),
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
