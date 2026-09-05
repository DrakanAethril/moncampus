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
 * The cahier de texte's own screen: a teacher's séances of one week, every class together
 * (design/design_handoff_cahier_de_texte_seances).
 *
 * It replaces the class picker one used to pass through: the class is no longer a question asked
 * before the screen, it is the left column's own grouping. Nothing is asked on arrival at all - the
 * current week, grouped by class - and both of the things a link can name are optional refinements
 * of that:
 *
 *  - `?class=<id>` opens that class's accordion, still in the by-class list;
 *  - `?date=<Y-m-d>` switches to the chronological list, moves the period to the calendar week
 *    holding that day, and unfolds it. A day with no séance is said in a sentence rather than left
 *    to read as a broken link.
 *
 * Everything the left column can do without the server - unfolding, switching séance - is done in
 * the browser (assets/controllers/lesson_log_board_controller.js), so the whole week is rendered at
 * once: one query per kind of thing, never one per séance. What does need the server is the period
 * itself, and those are plain links.
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

    #[Route(path: '/lesson-log', name: 'app_lesson_logs', methods: ['GET'])]
    public function index(
        Request $request,
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

        $classId = QueryValue::nullableInt($request, 'class');
        $date = $this->readDay($request, 'date');
        $week = $this->readDay($request, 'week');
        $requestedMode = QueryValue::trimmed($request, 'view');

        $today = new \DateTimeImmutable('today');
        $thisWeek = $periodBoard->weekStart(null, null, $today);
        $weekStart = $periodBoard->weekStart($week, $date, $today);
        $weekEnd = $weekStart->modify('+6 days');

        $sessions = $lessonSessionRepository->findAllForTeacherBetween($viewer, $weekStart, $weekEnd);
        $rows = $this->rowsFor($sessions);

        $viewMode = $periodBoard->viewMode(
            '' === $requestedMode ? null : $requestedMode,
            $classId,
            $date,
            $this->rememberedViewMode($request),
        );
        $this->rememberViewMode($request, $viewMode);

        $selectedId = $periodBoard->selectedSession($rows, QueryValue::nullableInt($request, 'seance'), $classId, $date);
        $decorated = $this->decorate($sessions, $lessonLogRepository, $assignmentRepository, $board);

        return $this->render('lesson_log/board.html.twig', [
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
            'openClassId' => $periodBoard->openClass($rows, $classId, $selectedId),
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
