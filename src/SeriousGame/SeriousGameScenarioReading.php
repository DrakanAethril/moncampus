<?php

declare(strict_types=1);

namespace App\SeriousGame;

use App\Entity\Program;
use App\Entity\SeriousGame;
use App\Entity\SeriousGameAnswer;
use App\Entity\SeriousGameSession;
use App\Entity\User;
use App\Enum\SeriousGameSessionMode;
use App\Enum\SeriousGameTicketStatus;
use App\Repository\SeriousGameAvatarRepository;
use App\Repository\SeriousGameProgressRepository;
use App\Repository\SeriousGameUnitStateRepository;
use App\SeriousGame\Scenario\ScenarioReadingRules;
use App\SeriousGame\Scenario\ScenarioStepKind;
use App\SeriousGame\Scenario\ScenarioTicket;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The teachers' reading of a game of the « scénario » mechanic (design/validated/serious-games-neko-con.md
 * §5), for the teachers of the student's classes and the administrators - SeriousGameVoter's
 * READ_CLASS and READ_STUDENT, as for every game:
 *
 * - **the class map**: students × tickets grouped by act, the status and the stars of each ticket, a
 *   mark where Inès' help was asked; filters « Bloqués », « Beaucoup d'aide » and « Inactifs depuis
 *   14 jours » (ScenarioReadingRules);
 * - **the notions to revisit**: per course revised, the share of tickets resolved without help and
 *   the most frequent misconceptions with how many students hold them - the page of the revision;
 * - **the student's sheet**: the chronology of their tickets, their named misconceptions, the help
 *   they asked, the criteria their deliveries failed. **Never their code**, which is not kept.
 *
 * Never sorted by score: by name or by last activity. Adults who play appear in no class.
 *
 * @phpstan-type AnswerRow array{player: int, ticket: string, stepId: ?string, help: bool, ok: bool, misconception: ?string, input: ?string, open: bool, at: \DateTimeImmutable}
 * @phpstan-type Cell array{status: ?string, stars: ?int, help: bool, blocked: bool}
 */
final readonly class SeriousGameScenarioReading
{
    public function __construct(
        private SeriousGameUnitStateRepository $states,
        private SeriousGameProgressRepository $progress,
        private SeriousGameAvatarRepository $avatars,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function forClass(Program $program, SeriousGame $game): array
    {
        $scenario = SeriousGameScenarioPlay::scenario($game);
        $students = self::sortedStudents($program);
        $states = $this->states->findForPlayers($students, $game);
        $progress = $this->progress->findForPlayers($students, $game);
        $avatars = $this->avatars->findForUsers($students);
        $answers = self::byPlayerAndTicket($this->answers($game, $students));
        $now = new \DateTimeImmutable();

        $rows = [];
        foreach ($students as $student) {
            $id = (int) $student->getId();
            $cells = [];
            $closed = 0;
            $opened = 0;
            $helped = 0;
            $blocked = false;
            foreach ($scenario->tickets() as $ticket) {
                $state = $states[$id][$ticket->id] ?? null;
                $status = $state?->getStatus();
                // A ticket closed in a game since started again reads as closed, with its best stars.
                if (null === $status && null !== $state?->getBest()) {
                    $status = $ticket->reassigns() ? SeriousGameTicketStatus::Reassigned : SeriousGameTicketStatus::Done;
                }
                $isBlocked = SeriousGameTicketStatus::Doing === $state?->getStatus()
                    && ScenarioReadingRules::blocked(self::kinds($ticket, array_values(array_filter($answers[$id][$ticket->id] ?? [], static fn (array $a): bool => $a['open']))));
                $help = ($state?->getHints() ?? 0) > 0;
                $cells[$ticket->id] = ['status' => $status?->value, 'stars' => $state?->getBest(), 'help' => $help, 'blocked' => $isBlocked];
                $closed += true === $status?->isClosed() ? 1 : 0;
                $isOpened = null !== $status && SeriousGameTicketStatus::Todo !== $status;
                $opened += $isOpened ? 1 : 0;
                $helped += $isOpened && $help ? 1 : 0;
                $blocked = $blocked || $isBlocked;
            }
            $lastPlayed = $progress[$id] ?? null;
            $rows[] = [
                'student' => $student,
                'avatar' => $avatars[$id] ?? null,
                'cells' => $cells,
                'lit' => $closed,
                'lastPlayedAt' => $lastPlayed?->getLastPlayedAt(),
                'blocked' => $blocked,
                'help' => ScenarioReadingRules::muchHelp($opened, $helped),
                'inactive' => null === $lastPlayed || $lastPlayed->getLastPlayedAt() < $now->modify('-'.SeriousGameClassReading::INACTIVE_DAYS.' days'),
            ];
        }

        $closedCounts = array_column($rows, 'lit');
        sort($closedCounts);
        $weekAgo = $now->modify('-7 days');
        $share = [];
        foreach ($scenario->tickets() as $ticket) {
            $done = \count(array_filter($rows, static fn (array $r): bool => \in_array($r['cells'][$ticket->id]['status'], ['done', 'reassigned'], true)));
            $share[$ticket->id] = [] === $rows ? 0 : $done / \count($rows);
        }
        $finalIds = array_map(static fn (ScenarioTicket $t): string => $t->id, array_values(array_filter($scenario->tickets(), static fn (ScenarioTicket $t): bool => $t->final)));

        return [
            'acts' => array_map(static fn (array $act): array => ['n' => $act['n'], 'title' => $act['title'], 'tickets' => $scenario->ticketsOfAct($act['n'])], $scenario->acts()),
            'rows' => $rows,
            'share' => $share,
            'kpis' => [
                'students' => \count($rows),
                'playedThisWeek' => \count(array_filter($rows, static fn (array $r): bool => null !== $r['lastPlayedAt'] && $r['lastPlayedAt'] >= $weekAgo)),
                'medianClosed' => [] === $closedCounts ? 0 : $closedCounts[intdiv(\count($closedCounts), 2)],
                'blocked' => \count(array_filter($rows, static fn (array $r): bool => $r['blocked'])),
                'online' => \count(array_filter($rows, static fn (array $r): bool => [] !== $finalIds && array_all($finalIds, static fn (string $id): bool => null !== $r['cells'][$id]['stars']))),
            ],
        ];
    }

    /**
     * The notions to revisit, course by course: the share of the class's closed tickets resolved
     * without help, and the misconceptions the class holds, the most widespread first.
     *
     * @return array{students: int, courses: list<array{course: string, tickets: int, closed: int, withoutHelp: ?float, misconceptions: list<array{id: string, label: string, students: int, answers: int}>}>}
     */
    public function notions(Program $program, SeriousGame $game): array
    {
        $scenario = SeriousGameScenarioPlay::scenario($game);
        $students = self::sortedStudents($program);
        $states = $this->states->findForPlayers($students, $game);
        $answers = $this->answers($game, $students);

        $courseOf = [];
        foreach ($scenario->tickets() as $ticket) {
            $courseOf[$ticket->id] = $ticket->course;
        }
        $held = [];
        foreach ($answers as $answer) {
            if (null === $answer['misconception']) {
                continue;
            }
            $course = $courseOf[$answer['ticket']] ?? '';
            $held[$course][$answer['misconception']]['students'][$answer['player']] = true;
            $held[$course][$answer['misconception']]['answers'] = ($held[$course][$answer['misconception']]['answers'] ?? 0) + 1;
        }

        $courses = [];
        foreach ($scenario->courses() as $course) {
            $tickets = array_values(array_filter($scenario->tickets(), static fn (ScenarioTicket $t): bool => $t->course === $course));
            $hints = [];
            foreach ($students as $student) {
                foreach ($tickets as $ticket) {
                    $state = $states[(int) $student->getId()][$ticket->id] ?? null;
                    if (null !== $state?->getBest()) {
                        $hints[] = $state->getHints();
                    }
                }
            }
            $misconceptions = [];
            foreach ($held[$course] ?? [] as $id => $entry) {
                $misconceptions[] = ['id' => (string) $id, 'label' => $scenario->misconceptionLabel((string) $id), 'students' => \count($entry['students']), 'answers' => $entry['answers']];
            }
            usort($misconceptions, static fn (array $a, array $b): int => [$b['students'], $b['answers']] <=> [$a['students'], $a['answers']]);
            $courses[] = ['course' => $course, 'tickets' => \count($tickets), 'closed' => \count($hints), 'withoutHelp' => ScenarioReadingRules::shareWithoutHelp($hints), 'misconceptions' => $misconceptions];
        }

        return ['students' => \count($students), 'courses' => $courses];
    }

    /**
     * One student's sheet: their tickets in the order they took them up, each with its status, its
     * stars, the help asked and what went wrong - named misconceptions, lines designated, the
     * criteria a delivery failed.
     *
     * @return array<string, mixed>
     */
    public function forStudent(User $student, SeriousGame $game): array
    {
        $scenario = SeriousGameScenarioPlay::scenario($game);
        $states = $this->states->findForPlayer($student, $game);
        $answers = self::byPlayerAndTicket($this->answers($game, [$student]))[(int) $student->getId()] ?? [];
        $closings = $this->closings($student, $game);

        $tickets = [];
        foreach ($scenario->tickets() as $ticket) {
            $state = $states[$ticket->id] ?? null;
            $list = $answers[$ticket->id] ?? [];
            if ([] === $list && null === $state?->getBest() && SeriousGameTicketStatus::Doing !== $state?->getStatus()) {
                continue;
            }
            $events = [];
            foreach ($list as $answer) {
                if ($answer['ok']) {
                    continue;
                }
                $step = $answer['help'] ? $ticket->guideStep((string) $answer['stepId']) : $ticket->step($answer['stepId']);
                $kind = $step->kind ?? ScenarioStepKind::Choice;
                if (ScenarioStepKind::Password === $kind) {
                    continue;
                }
                $events[] = [
                    'at' => $answer['at'],
                    'help' => $answer['help'],
                    'kind' => $kind->value,
                    'misconception' => null === $answer['misconception'] ? null : $scenario->misconceptionLabel($answer['misconception']),
                    'input' => ScenarioStepKind::Line === $kind || ScenarioStepKind::Fix === $kind ? $answer['input'] : null,
                ];
            }
            $tickets[] = [
                'ticket' => $ticket,
                'status' => ($state?->getStatus() ?? (null !== $state?->getBest() ? ($ticket->reassigns() ? SeriousGameTicketStatus::Reassigned : SeriousGameTicketStatus::Done) : null)),
                'stars' => $state?->getBest(),
                'hints' => $state?->getHints() ?? 0,
                'startedAt' => [] === $list ? null : $list[0]['at'],
                'closedAt' => $closings[$ticket->id] ?? null,
                'events' => $events,
            ];
        }
        usort($tickets, static fn (array $a, array $b): int => [$a['startedAt'] ?? $a['closedAt'] ?? new \DateTimeImmutable('2100-01-01'), $a['ticket']->id] <=> [$b['startedAt'] ?? $b['closedAt'] ?? new \DateTimeImmutable('2100-01-01'), $b['ticket']->id]);

        return ['game' => $game, 'scenario' => true, 'tickets' => $tickets];
    }

    /**
     * Every answer of a group of players in the game, oldest first.
     *
     * @param list<User> $players
     *
     * @return list<AnswerRow>
     */
    private function answers(SeriousGame $game, array $players): array
    {
        if ([] === $players) {
            return [];
        }

        /** @var list<array{player: int|string, ticket: string, stepId: ?string, help: bool|int|string, ok: bool|int|string|null, misconception: ?string, input: ?string, endedAt: ?\DateTimeImmutable, answeredAt: ?\DateTimeImmutable}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(s.player) AS player', 'a.unitCode AS ticket', 'a.stepId', 'a.help', 'a.ok', 'a.misconception', 'a.input', 's.endedAt', 'a.answeredAt')
            ->from(SeriousGameAnswer::class, 'a')
            ->innerJoin('a.session', 's')
            ->andWhere('s.game = :game')
            ->andWhere('s.player IN (:players)')
            ->andWhere('s.mode = :mode')
            ->andWhere('a.answeredAt IS NOT NULL')
            ->setParameter('game', $game)
            ->setParameter('players', $players)
            ->setParameter('mode', SeriousGameSessionMode::Ticket)
            ->orderBy('a.answeredAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            if (null === $row['answeredAt']) {
                continue;
            }
            $out[] = [
                'player' => (int) $row['player'],
                'ticket' => $row['ticket'],
                'stepId' => $row['stepId'],
                'help' => (bool) $row['help'],
                'ok' => (bool) $row['ok'],
                'misconception' => $row['misconception'],
                'input' => $row['input'],
                'open' => null === $row['endedAt'],
                'at' => $row['answeredAt'],
            ];
        }

        return $out;
    }

    /**
     * When each ticket was last closed: ticket => date.
     *
     * @return array<string, \DateTimeImmutable>
     */
    private function closings(User $player, SeriousGame $game): array
    {
        /** @var list<SeriousGameSession> $sessions */
        $sessions = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(SeriousGameSession::class, 's')
            ->andWhere('s.player = :player')
            ->andWhere('s.game = :game')
            ->andWhere('s.mode = :mode')
            ->andWhere('s.score IS NOT NULL')
            ->setParameter('player', $player)
            ->setParameter('game', $game)
            ->setParameter('mode', SeriousGameSessionMode::Ticket)
            ->orderBy('s.endedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($sessions as $session) {
            if (null !== $session->getUnitCode() && null !== $session->getEndedAt()) {
                $out[$session->getUnitCode()] = $session->getEndedAt();
            }
        }

        return $out;
    }

    /**
     * @param list<AnswerRow> $answers
     *
     * @return array<int, array<string, list<AnswerRow>>>
     */
    private static function byPlayerAndTicket(array $answers): array
    {
        $out = [];
        foreach ($answers as $answer) {
            $out[$answer['player']][$answer['ticket']][] = $answer;
        }

        return $out;
    }

    /**
     * @param list<AnswerRow> $answers
     *
     * @return list<array{kind: ScenarioStepKind, ok: bool, help: bool}>
     */
    private static function kinds(ScenarioTicket $ticket, array $answers): array
    {
        return array_map(static function (array $a) use ($ticket): array {
            $step = $a['help'] ? $ticket->guideStep((string) $a['stepId']) : $ticket->step($a['stepId']);

            return ['kind' => $step->kind ?? ScenarioStepKind::Choice, 'ok' => $a['ok'], 'help' => $a['help']];
        }, $answers);
    }

    /** @return list<User> the class's students, by name */
    private static function sortedStudents(Program $program): array
    {
        /** @var list<User> $students */
        $students = array_values($program->getStudents()->toArray());
        usort($students, static fn (User $a, User $b): int => [mb_strtolower((string) $a->getLastname()), mb_strtolower((string) $a->getFirstname())] <=> [mb_strtolower((string) $b->getLastname()), mb_strtolower((string) $b->getFirstname())]);

        return $students;
    }
}
