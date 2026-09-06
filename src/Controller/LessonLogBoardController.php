<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Assignment;
use App\Entity\LessonLog;
use App\Entity\LessonLogAttachment;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\LessonLogSection;
use App\Enum\LessonLogVisibility;
use App\Repository\AssignmentRepository;
use App\Repository\LessonLogRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Security\StructureAccessChecker;
use App\Service\LessonLogBoard;
use App\Service\LessonLogPeriodBoard;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The cahier de texte, in one screen and two scopes: a teacher's séances of one week, every class
 * together (`/lesson-log`) or narrowed to one (`/programs/{id}/lesson-log`) -
 * design/design_handoff_cahier_de_texte_seances.
 *
 * **One screen, deliberately.** The class-scoped address used to open a different screen of its own
 * (the course view of design_handoff_cahier_de_texte 1b, with its « séances de toute la formation »
 * switch); it is now the same screen with the class picker on its title line set to that class, and
 * the switch is gone - showing a colleague's séances will come back elsewhere. Two screens for one
 * question is what this replaces.
 *
 * Nothing is asked on arrival: the current week, every class, grouped by class. Two things a link
 * can name refine that:
 *
 *  - the **class** is the route, and the picker navigates between the two addresses. A scoped
 *    screen forces the chronological list - a by-class accordion of one class would be a single
 *    group holding everything - and says so on the greyed-out tab;
 *  - `?date=<Y-m-d>` switches to the chronological list, moves the period to the calendar week
 *    holding that day, and unfolds it. A day with no séance is said in a sentence rather than left
 *    to read as a broken link.
 *
 * `?class=` was a third way of naming the class and is now only a redirect onto the route: one
 * address per screen, and no rule left to arbitrate between the two.
 *
 * Everything the left column can do without the server - unfolding, switching séance - is done in
 * the browser (assets/controllers/lesson_log_board_controller.js), so the whole week is rendered at
 * once: one query per kind of thing, never one per séance. What does need the server is the period
 * and the class, and those are plain links.
 *
 * This is a reading screen. Writing happens on the séance page, and who may write there is
 * App\Security\LessonLogEditors' question, not this one's.
 *
 * @phpstan-type SectionView array{content: string|null, works: list<Assignment>, attachments: list<LessonLogAttachment>, state: string, visibility: LessonLogVisibility|null, visibleAt: \DateTimeImmutable|null}
 * @phpstan-type SeanceRow array{session: LessonSession, log: LessonLog|null, state: string, sections: array<string, SectionView>}
 */
#[RequiresFeature(Feature::LessonLog)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class LessonLogBoardController extends AbstractController
{
    /**
     * The segmented control is remembered for the user, as the handoff asks. The HTTP session
     * rather than a column on User: it is a way of looking at one screen, not a preference the
     * account carries around, and it costs no migration to change one's mind about.
     */
    private const string VIEW_MODE_KEY = 'lesson_log.view_mode';

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    #[Route(path: '/lesson-log', name: 'app_lesson_logs', methods: ['GET'])]
    public function index(
        Request $request,
        LessonSessionRepository $lessonSessionRepository,
        LessonLogRepository $lessonLogRepository,
        AssignmentRepository $assignmentRepository,
        LessonLogPeriodBoard $periodBoard,
        LessonLogBoard $board,
    ): Response {
        // `?class=` used to be a second way of naming the class. It is the route now, and a link
        // still carrying it is sent there rather than quietly ignored.
        $classId = QueryValue::nullableInt($request, 'class');
        if (null !== $classId) {
            return $this->redirectToRoute('app_program_lesson_logs', ['id' => $classId] + $this->carriedOver($request));
        }

        return $this->board($request, null, $lessonSessionRepository, $lessonLogRepository, $assignmentRepository, $periodBoard, $board);
    }

    /**
     * The same screen narrowed to one class - what the picker on the title line navigates to, and
     * where a séance's breadcrumb comes back to.
     *
     * The right to open it is the class's teaching team plus staff, exactly as the course view it
     * replaces required. It carries no `timetableManagementEnabled` guard any more: that guard
     * belonged to a timetable-management screen, the séance page itself never had one, and this is
     * now a reading of the viewer's own séances.
     */
    #[Route(path: '/programs/{id}/lesson-log', name: 'app_program_lesson_logs', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function forProgram(
        int $id,
        Request $request,
        ProgramRepository $programRepository,
        LessonSessionRepository $lessonSessionRepository,
        LessonLogRepository $lessonLogRepository,
        AssignmentRepository $assignmentRepository,
        LessonLogPeriodBoard $periodBoard,
        LessonLogBoard $board,
    ): Response {
        $program = $programRepository->find($id) ?? throw $this->createNotFoundException();

        if (!$this->accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        return $this->board($request, $program, $lessonSessionRepository, $lessonLogRepository, $assignmentRepository, $periodBoard, $board);
    }

    /**
     * @param ?Program $program the class the screen is narrowed to, null for every class at once
     */
    private function board(
        Request $request,
        ?Program $program,
        LessonSessionRepository $lessonSessionRepository,
        LessonLogRepository $lessonLogRepository,
        AssignmentRepository $assignmentRepository,
        LessonLogPeriodBoard $periodBoard,
        LessonLogBoard $board,
    ): Response {
        $viewer = $this->getUser();
        if (!$viewer instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $date = $this->readDay($request, 'date');
        $week = $this->readDay($request, 'week');
        $requestedSeance = QueryValue::nullableInt($request, 'seance');
        $requestedMode = QueryValue::trimmed($request, 'view');

        $today = new \DateTimeImmutable('today');
        $thisWeek = $periodBoard->weekStart(null, null, null, $today);
        $weekStart = $periodBoard->weekStart(
            $week,
            $date,
            $this->dayOfOwnSession($lessonSessionRepository, $viewer, $program, $requestedSeance),
            $today,
        );
        $weekEnd = $weekStart->modify('+6 days');

        $sessions = $lessonSessionRepository->findAllForTeacherBetween($viewer, $weekStart, $weekEnd);
        if (null !== $program) {
            $sessions = array_values(array_filter(
                $sessions,
                static fn (LessonSession $session): bool => $session->getProgram()?->getId() === $program->getId(),
            ));
        }
        $rows = $this->rowsFor($sessions);

        // One class, one group: the accordion would hold everything and close nothing, so the
        // scoped screen has only the chronological list and the other tab is greyed out. The mode
        // the user last chose is left untouched - they did not ask to change it.
        $viewMode = null !== $program
            ? LessonLogPeriodBoard::MODE_CHRONOLOGICAL
            : $periodBoard->viewMode('' === $requestedMode ? null : $requestedMode, $date, $this->rememberedViewMode($request));

        if (null === $program) {
            $this->rememberViewMode($request, $viewMode);
        }

        $selectedId = $periodBoard->selectedSession($rows, $requestedSeance, $date);
        $decorated = $this->decorate($sessions, $lessonLogRepository, $assignmentRepository, $board);

        return $this->render('lesson_log/board.html.twig', [
            'program' => $program,
            // Every class the viewer actually has a créneau in, whatever the week - the picker must
            // not lose an option as one walks through a holiday. Ordered by short name, and never
            // widened to the classes one merely has the right to look at: a class one does not
            // teach would open on an empty screen.
            'pickerPrograms' => $lessonSessionRepository->findDistinctProgramsForTeacher($viewer),
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'previousWeek' => $weekStart->modify('-7 days'),
            'nextWeek' => $weekStart->modify('+7 days'),
            'today' => $today,
            // The three shortcuts under the calendar. Computed from today rather than from the
            // period on display, which is exactly what makes « S. courante » a way back.
            'shortcutWeeks' => [
                'last' => $thisWeek->modify('-7 days'),
                'current' => $thisWeek,
                'next' => $thisWeek->modify('+7 days'),
            ],
            'viewMode' => $viewMode,
            'modeClass' => LessonLogPeriodBoard::MODE_CLASS,
            'modeChronological' => LessonLogPeriodBoard::MODE_CHRONOLOGICAL,
            'selectedId' => $selectedId,
            'openClassId' => $periodBoard->openClass($rows, $selectedId),
            'openDays' => $periodBoard->openDays($rows, $date, $selectedId),
            // A day named in the link that the viewer has no séance on. Not the same thing as an
            // empty week, and the screen says the two differently.
            'emptyDate' => $periodBoard->isDateWithoutSession($rows, $date),
            'emptyDay' => null === $date ? null : new \DateTimeImmutable($date),
            'classGroups' => $this->groupByClass($decorated),
            'dayGroups' => $this->groupByDay($decorated),
            'sections' => LessonLogSection::cases(),
        ]);
    }

    /**
     * The day the séance `?seance=` names falls on, when it is one the viewer delivers - what lets
     * the period follow a link that names a séance rather than a week.
     *
     * Without it, `?seance=` only ever selected something inside the current week: a séance from any
     * other week was quietly swapped for that week's first one, so the screen showed a séance nobody
     * asked for and offered no way at all to reach the one that was named.
     *
     * Answers null rather than moving the period for a séance the screen could not show anyway - an
     * id that names nothing, a colleague's créneau, or, on the class-scoped address, another class's
     * séance. Landing on an empty week would only replace one silence with another.
     */
    private function dayOfOwnSession(LessonSessionRepository $sessions, User $viewer, ?Program $program, ?int $sessionId): ?string
    {
        if (null === $sessionId) {
            return null;
        }

        $session = $sessions->find($sessionId);
        if (null === $session || $session->getTeacher() !== $viewer) {
            return null;
        }

        if (null !== $program && $session->getProgram()?->getId() !== $program->getId()) {
            return null;
        }

        return $session->getDay()?->format('Y-m-d');
    }

    /**
     * What a redirect off `?class=` keeps: the period and the séance, so that an old link lands on
     * the same week it named rather than on today's.
     *
     * @return array<string, string|int>
     */
    private function carriedOver(Request $request): array
    {
        $carried = [];
        foreach (['week', 'date'] as $key) {
            $day = $this->readDay($request, $key);
            if (null !== $day) {
                $carried[$key] = $day;
            }
        }

        $seance = QueryValue::nullableInt($request, 'seance');

        return null === $seance ? $carried : $carried + ['seance' => $seance];
    }

    /**
     * A `Y-m-d` query parameter, or null when it is absent or unreadable.
     *
     * Read as a string and validated by re-formatting rather than through DateTime's own leniency:
     * `new \DateTimeImmutable('mardi')` succeeds, and a filter bar submitting `?date=` is ordinary
     * (see App\Service\QueryValue's own note on the empty string).
     */
    private function readDay(Request $request, string $key): ?string
    {
        $raw = QueryValue::trimmed($request, $key);
        if ('' === $raw) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return false !== $day && $day->format('Y-m-d') === $raw ? $raw : null;
    }

    private function rememberedViewMode(Request $request): ?string
    {
        $remembered = $request->getSession()->get(self::VIEW_MODE_KEY);

        return \is_string($remembered) ? $remembered : null;
    }

    private function rememberViewMode(Request $request, string $viewMode): void
    {
        $request->getSession()->set(self::VIEW_MODE_KEY, $viewMode);
    }

    /**
     * The séances reduced to what the period board decides on - an id, a class, a day.
     *
     * @param list<LessonSession> $sessions
     *
     * @return list<array{id: int, classId: int, day: string}>
     */
    private function rowsFor(array $sessions): array
    {
        $rows = [];
        foreach ($sessions as $session) {
            $id = $session->getId();
            $classId = $session->getProgram()?->getId();
            $day = $session->getDay();

            // A créneau missing any of the three cannot be placed in either list, and is therefore
            // not something to select or unfold either.
            if (null !== $id && null !== $classId && null !== $day) {
                $rows[] = ['id' => $id, 'classId' => $classId, 'day' => $day->format('Y-m-d')];
            }
        }

        return $rows;
    }

    /**
     * The left column's by-class list: one entry per class the viewer teaches this week, its
     * séances in chronological order. A class with no séance in the period is simply absent.
     *
     * @param list<SeanceRow> $rows
     *
     * @return list<array{program: Program, rows: list<SeanceRow>}>
     */
    private function groupByClass(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $program = $row['session']->getProgram();
            if (null === $program) {
                continue;
            }

            $groups[$program->getId()] ??= ['program' => $program, 'rows' => []];
            $groups[$program->getId()]['rows'][] = $row;
        }

        return array_values($groups);
    }

    /**
     * The left column's chronological list: one entry per day that carries a séance, no class
     * grouping.
     *
     * @param list<SeanceRow> $rows
     *
     * @return list<array{day: \DateTimeImmutable, rows: list<SeanceRow>}>
     */
    private function groupByDay(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $day = $row['session']->getDay();
            if (null === $day) {
                continue;
            }

            $groups[$day->format('Y-m-d')] ??= ['day' => $day, 'rows' => []];
            $groups[$day->format('Y-m-d')]['rows'][] = $row;
        }

        ksort($groups);

        return array_values($groups);
    }

    /**
     * Each séance with its cahier de texte, its state and its three parts - the whole week at once,
     * so that clicking a row swaps a block that is already on the page.
     *
     * Three queries whatever the number of séances: the créneaux, their logs (attachments joined),
     * their assignments. Called once and handed to both groupings, which walk the same list.
     *
     * @param list<LessonSession> $sessions
     *
     * @return list<SeanceRow>
     */
    private function decorate(array $sessions, LessonLogRepository $logs, AssignmentRepository $assignments, LessonLogBoard $board): array
    {
        $logBySessionId = [];
        foreach ($logs->findForSessions($sessions) as $log) {
            $logBySessionId[$log->getLessonSession()?->getId()] = $log;
        }

        /** @var array<int, array<string, list<Assignment>>> $worksBySessionId */
        $worksBySessionId = [];
        foreach ($assignments->findForLessonSessions($sessions) as $work) {
            $sessionId = $work->getLessonSession()?->getId();
            if (null === $sessionId) {
                continue;
            }

            $section = $work->getLessonLogSection() ?? LessonLogSection::After;
            $worksBySessionId[$sessionId][$section->value][] = $work;
        }

        $rows = [];
        foreach ($sessions as $session) {
            $sessionId = (int) $session->getId();
            $log = $logBySessionId[$sessionId] ?? null;
            $works = $worksBySessionId[$sessionId] ?? [];

            $sections = [];
            foreach (LessonLogSection::cases() as $section) {
                $sectionWorks = $works[$section->value] ?? [];
                $attachments = null === $log ? [] : $log->getAttachmentsForSection($section)->toArray();

                $sections[$section->value] = [
                    'content' => $log?->getContent($section),
                    'works' => $sectionWorks,
                    'attachments' => array_values($attachments),
                    'state' => $board->sectionStateOf(
                        $log?->getContent($section),
                        [] !== $sectionWorks || [] !== $attachments,
                    ),
                    'visibility' => $log?->getVisibility($section),
                    'visibleAt' => $log?->getVisibleAt($section),
                ];
            }

            $rows[] = [
                'session' => $session,
                'log' => $log,
                'state' => $board->sessionStateOf(
                    $log?->getContent(LessonLogSection::Before),
                    $log?->getContent(LessonLogSection::During),
                    $log?->getContent(LessonLogSection::After),
                    [] !== $works || (null !== $log && !$log->getAttachments()->isEmpty()),
                ),
                'sections' => $sections,
            ];
        }

        return $rows;
    }
}
