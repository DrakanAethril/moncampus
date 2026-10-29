<?php

declare(strict_types=1);

namespace App\SeriousGame\Stats;

use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\SeriousGame;
use App\Entity\SeriousGameAnswer;
use App\Entity\SeriousGameProgress;
use App\Entity\SeriousGameSession;
use App\Entity\SeriousGameUnitState;
use App\Entity\User;
use App\Enum\SeriousGameSessionMode;
use App\SeriousGame\Rules\AcquisitionRules;
use App\SeriousGame\Scenario\ScenarioRules;
use App\SeriousGame\Scenario\ScenarioStep;
use App\SeriousGame\Scenario\ScenarioStepKind;
use App\SeriousGame\SeriousGamePlay;
use App\SeriousGame\SeriousGameScenarioPlay;
use App\Service\SeriousGame\SeriousGameRules;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The statistics of a serious game (design/validated/serious-games-neko-con.md §6), for every game
 * and both mechanics, on the establishment or on one class, over a school year:
 *
 * - **usage**, read on the sessions and their answers: different players per week, sessions, time
 *   played (pauses of more than ten minutes left out), days of activity, the share played on school
 *   time;
 * - **progression**: the funnel by chapter - how many start, reach each act or constellation,
 *   finish - and the units most often left;
 * - **the analysis of the units**: for a scenario, per ticket, success at the first try, deliveries,
 *   the share asking for help, the median time, the criteria that fail most, and for each question
 *   the spread of the options chosen first; for a series, the success per unit and its typical
 *   errors;
 * - **per class, the list of the students** - played or not, last activity, time played, chapter
 *   reached, units succeeded -, sorted by name or last activity, never by score;
 * - the comparison from one school year to another.
 *
 * Read by every teacher and the administrator (SeriousGameVoter::READ_STATS), with the names. **Only
 * students count**: an adult plays without being counted. Who gets what wrong stays the reading of
 * a student (§5): no misconception is attached to a name here.
 *
 * @phpstan-type Event array{player: int, session: int, mode: string, unit: ?string, at: int}
 * @phpstan-type AnswerRow array{player: int, session: int, mode: string, unit: string, stepId: ?string, help: bool, ok: bool, misconception: ?string, input: ?string, at: int}
 */
final readonly class SeriousGameStatistics
{
    /** How many weeks the « players per week » row shows. */
    public const int WEEKS = 12;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SeriousGamePlay $play,
    ) {
    }

    /** @return array<string, mixed> */
    public function forGame(SeriousGame $game, ?Program $program, ?SchoolYear $year): array
    {
        [$from, $to] = self::window($year);
        $students = null === $program ? $this->playersOf($game) : self::studentsOf($program);
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $students);
        $sessions = $this->sessions($game, $ids, $from, $to);
        $answers = $this->answers($game, $ids, $from, $to);
        $states = $this->states($game, $ids);

        $moments = [];
        foreach ($sessions as $session) {
            $moments[$session['player']][] = $session['at'];
            if (null !== $session['endedAt']) {
                $moments[$session['player']][] = $session['endedAt'];
            }
        }
        foreach ($answers as $answer) {
            $moments[$answer['player']][] = $answer['at'];
        }

        $out = [
            'usage' => self::usage($moments, \count($sessions), $to),
            'progression' => $game->isScenario() ? $this->scenarioProgression($game, $sessions, $states) : $this->seriesProgression($game, $sessions, $states),
            'units' => $game->isScenario() ? $this->scenarioUnits($game, $answers, $sessions, $states) : $this->seriesUnits($game, $answers),
            'students' => null === $program ? [] : $this->studentRows($game, $students, $moments, $sessions, $states),
            'population' => \count($students),
        ];

        return $out;
    }

    /**
     * One line per school year: players, sessions, answers and the players who finished.
     *
     * @param list<SchoolYear> $years
     *
     * @return list<array{year: SchoolYear, players: int, sessions: int, answers: int}>
     */
    public function years(SeriousGame $game, ?Program $program, array $years): array
    {
        $students = null === $program ? $this->playersOf($game) : self::studentsOf($program);
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $students);
        $rows = [];
        foreach ($years as $year) {
            [$from, $to] = self::window($year);
            $sessions = $this->sessions($game, $ids, $from, $to);
            $rows[] = [
                'year' => $year,
                'players' => \count(array_unique(array_column($sessions, 'player'))),
                'sessions' => \count($sessions),
                'answers' => \count($this->answers($game, $ids, $from, $to)),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, list<int>> $moments player => moments
     *
     * @return array<string, mixed>
     */
    private static function usage(array $moments, int $sessions, ?\DateTimeImmutable $to): array
    {
        $weeks = [];
        $days = 0;
        $split = ['school' => 0, 'outside' => 0];
        $perPlayer = [];
        foreach ($moments as $player => $list) {
            $playerDays = [];
            foreach ($list as $moment) {
                $weeks[PlayStats::week($moment)][$player] = true;
                $playerDays[PlayStats::day($moment)] = true;
            }
            $days += \count($playerDays);
            $s = PlayStats::schoolSplit($list);
            $split['school'] += $s['school'];
            $split['outside'] += $s['outside'];
            $perPlayer[] = $s['school'] + $s['outside'];
        }

        // The last weeks up to the end of the window (today when it is not over), empty ones shown.
        $end = null === $to || $to > new \DateTimeImmutable() ? new \DateTimeImmutable() : $to;
        $perWeek = [];
        for ($i = self::WEEKS - 1; $i >= 0; --$i) {
            $week = PlayStats::week($end->modify('-'.$i.' weeks')->getTimestamp());
            $perWeek[] = ['week' => $week, 'players' => \count($weeks[$week] ?? [])];
        }
        $total = $split['school'] + $split['outside'];

        return [
            'players' => \count($moments),
            'sessions' => $sessions,
            'seconds' => $total,
            'medianSeconds' => PlayStats::median($perPlayer),
            'days' => $days,
            'daysPerPlayer' => [] === $moments ? null : $days / \count($moments),
            'schoolShare' => 0 === $total ? null : (float) $split['school'] / $total,
            'perWeek' => $perWeek,
        ];
    }

    /**
     * @param list<array{player: int, session: int, mode: string, unit: ?string, at: int, endedAt: ?int, score: ?int}> $sessions
     * @param array<int, array<string, SeriousGameUnitState>>                                                           $states
     *
     * @return array<string, mixed>
     */
    private function scenarioProgression(SeriousGame $game, array $sessions, array $states): array
    {
        $scenario = SeriousGameScenarioPlay::scenario($game);
        $started = array_unique(array_column($sessions, 'player'));
        $steps = [['label' => 'seriousGameFunnelStarted', 'params' => [], 'players' => \count($started)]];
        $final = null;
        foreach ($scenario->tickets() as $ticket) {
            $final = $ticket->final ? $ticket : $final;
        }
        foreach ($scenario->acts() as $act) {
            $ticketIds = array_map(static fn ($t): string => $t->id, $scenario->ticketsOfAct($act['n']));
            $reached = 0;
            foreach ($states as $playerStates) {
                $reached += array_any($ticketIds, static fn (string $id): bool => null !== ($playerStates[$id] ?? null) && ($playerStates[$id]->isOnTheBoard() || null !== $playerStates[$id]->getBest())) ? 1 : 0;
            }
            $steps[] = ['label' => 'seriousGameFunnelAct', 'params' => ['%n%' => $act['n'], '%title%' => $act['title']], 'players' => $reached];
        }
        $finished = 0;
        foreach ($states as $playerStates) {
            $finished += null !== $final && null !== ($playerStates[$final->id] ?? null)?->getBest() ? 1 : 0;
        }
        $steps[] = ['label' => 'seriousGameFunnelFinished', 'params' => [], 'players' => $finished];

        $left = [];
        foreach ($scenario->tickets() as $ticket) {
            $count = 0;
            foreach ($states as $playerStates) {
                $count += 'doing' === ($playerStates[$ticket->id] ?? null)?->getStatus()?->value ? 1 : 0;
            }
            if ($count > 0) {
                $left[] = ['unit' => $ticket->id.' · '.$ticket->title, 'players' => $count];
            }
        }
        usort($left, static fn (array $a, array $b): int => $b['players'] <=> $a['players']);

        return ['funnel' => $steps, 'left' => \array_slice($left, 0, 8)];
    }

    /**
     * @param list<array{player: int, session: int, mode: string, unit: ?string, at: int, endedAt: ?int, score: ?int}> $sessions
     * @param array<int, array<string, SeriousGameUnitState>>                                                           $states
     *
     * @return array<string, mixed>
     */
    private function seriesProgression(SeriousGame $game, array $sessions, array $states): array
    {
        $manifest = SeriousGamePlay::manifest($game);
        $started = array_unique(array_column($sessions, 'player'));
        $steps = [['label' => 'seriousGameFunnelStarted', 'params' => [], 'players' => \count($started)]];
        foreach ($manifest->chapters() as $chapter) {
            $codes = array_column($chapter['units'], 'code');
            $reached = 0;
            foreach ($states as $playerStates) {
                $reached += array_any($codes, static fn (string $code): bool => ($playerStates[$code] ?? null)?->getSeriesCount() > 0) ? 1 : 0;
            }
            $steps[] = ['label' => 'seriousGameFunnelChapter', 'params' => ['%chapter%' => $chapter['label']], 'players' => $reached];
        }
        $finished = 0;
        $codes = $manifest->unitCodes();
        foreach ($states as $playerStates) {
            $finished += [] !== $codes && array_all($codes, static fn (string $code): bool => AcquisitionRules::isLit(($playerStates[$code] ?? null)?->getBest(), $manifest->litAt())) ? 1 : 0;
        }
        $steps[] = ['label' => 'seriousGameFunnelFinished', 'params' => [], 'players' => $finished];

        $left = [];
        foreach ($manifest->units() as $unit) {
            $count = 0;
            foreach ($states as $playerStates) {
                $state = $playerStates[$unit['code']] ?? null;
                $count += null !== $state && $state->getSeriesCount() > 0 && !AcquisitionRules::isLit($state->getBest(), $manifest->litAt()) ? 1 : 0;
            }
            if ($count > 0) {
                $left[] = ['unit' => $unit['label'], 'players' => $count];
            }
        }
        usort($left, static fn (array $a, array $b): int => $b['players'] <=> $a['players']);

        return ['funnel' => $steps, 'left' => \array_slice($left, 0, 8)];
    }

    /**
     * Per ticket: success at the first try, deliveries, help, median time, failing criteria, and the
     * spread of the options each student chose first.
     *
     * @param list<AnswerRow>                                                                                          $answers
     * @param list<array{player: int, session: int, mode: string, unit: ?string, at: int, endedAt: ?int, score: ?int}> $sessions
     * @param array<int, array<string, SeriousGameUnitState>>                                                          $states
     *
     * @return list<array<string, mixed>>
     */
    private function scenarioUnits(SeriousGame $game, array $answers, array $sessions, array $states): array
    {
        $scenario = SeriousGameScenarioPlay::scenario($game);
        $byTicket = [];
        foreach ($answers as $answer) {
            $byTicket[$answer['unit']][$answer['player']][] = $answer;
        }
        $openings = [];
        foreach ($sessions as $session) {
            if (null !== $session['unit']) {
                $openings[$session['unit']][$session['player']][] = $session['at'];
            }
        }

        $rows = [];
        foreach ($scenario->tickets() as $ticket) {
            $players = $byTicket[$ticket->id] ?? [];
            $closed = 0;
            $firstTry = 0;
            $deliveries = [];
            $times = [];
            $refused = [];
            foreach ($players as $player => $list) {
                $kinds = array_map(static function (array $a) use ($ticket): array {
                    $step = $a['help'] ? $ticket->guideStep((string) $a['stepId']) : $ticket->step($a['stepId']);

                    return ['kind' => $step->kind ?? ScenarioStepKind::Choice, 'ok' => $a['ok'], 'help' => $a['help']];
                }, $list);
                if (null !== ($states[$player][$ticket->id] ?? null)?->getBest()) {
                    ++$closed;
                    $firstTry += 0 === ScenarioRules::errors($kinds) ? 1 : 0;
                    $times[] = PlayStats::playSeconds([...array_column($list, 'at'), ...($openings[$ticket->id][$player] ?? [])]);
                }
                $fixes = array_values(array_filter($list, static fn (array $a): bool => !$a['help'] && ScenarioStepKind::Fix === ($ticket->step($a['stepId'])->kind ?? null)));
                if ([] !== $fixes) {
                    $deliveries[] = \count($fixes);
                }
                foreach ($fixes as $fix) {
                    if (!$fix['ok']) {
                        $refused[] = $fix['input'];
                    }
                }
            }

            $opened = 0;
            $helped = 0;
            foreach ($states as $playerStates) {
                $state = $playerStates[$ticket->id] ?? null;
                if (null !== $state && (null !== $state->getBest() || 'doing' === $state->getStatus()?->value)) {
                    ++$opened;
                    $helped += $state->getHints() > 0 ? 1 : 0;
                }
            }

            $questions = [];
            foreach ($ticket->steps as $step) {
                if (!$step->kind->hasOptions()) {
                    continue;
                }
                $stepAnswers = [];
                foreach ($players as $player => $list) {
                    foreach ($list as $a) {
                        if (!$a['help'] && $a['stepId'] === $step->id) {
                            $stepAnswers[] = ['player' => $player, 'input' => $a['input']];
                        }
                    }
                }
                $questions[] = self::spread($step, PlayStats::firstChoices($stepAnswers, \count($step->options)), $scenario->misconceptionLabel(...));
            }

            $rows[] = [
                'code' => $ticket->id,
                'label' => $ticket->title,
                'course' => $ticket->course,
                'players' => \count($players),
                'closed' => $closed,
                'firstTry' => 0 === $closed ? null : (float) $firstTry / $closed,
                'deliveries' => [] === $deliveries ? null : array_sum($deliveries) / \count($deliveries),
                'help' => 0 === $opened ? null : (float) $helped / $opened,
                'medianSeconds' => PlayStats::median($times),
                'criteria' => \array_slice(PlayStats::criteria($refused), 0, 3, true),
                'questions' => $questions,
            ];
        }

        return $rows;
    }

    /**
     * @param list<int>               $counts
     * @param \Closure(string): string $label
     *
     * @return array<string, mixed>
     */
    private static function spread(ScenarioStep $step, array $counts, \Closure $label): array
    {
        $total = array_sum($counts);
        $right = 0;
        foreach ($step->options as $rank => $option) {
            $right = $option['ok'] ? ($counts[$rank] ?? 0) : $right;
        }
        $options = [];
        foreach ($step->options as $rank => $option) {
            $count = $counts[$rank] ?? 0;
            $options[] = [
                'text' => $option['text'],
                'ok' => $option['ok'],
                'misconception' => null === $option['misconception'] ? null : $label($option['misconception']),
                'count' => $count,
                'share' => 0 === $total ? null : (float) $count / $total,
                // A wrong option taken more than the right one: the question or the notion is unclear.
                'overRight' => !$option['ok'] && $count > $right,
                // A wrong option nobody takes, once enough students answered, serves nothing.
                'unused' => !$option['ok'] && 0 === $count && $total >= 10,
            ];
        }

        return ['id' => $step->id, 'kind' => $step->kind->value, 'prompt' => $step->prompt, 'answered' => $total, 'options' => $options];
    }

    /**
     * Per unit of a series: the share of answers right and the typical errors.
     *
     * @param list<AnswerRow> $answers
     *
     * @return list<array<string, mixed>>
     */
    private function seriesUnits(SeriousGame $game, array $answers): array
    {
        $manifest = SeriousGamePlay::manifest($game);
        $source = $this->play->source($game);
        $byUnit = [];
        foreach ($answers as $answer) {
            if (SeriousGameSessionMode::Series->value !== $answer['mode']) {
                continue;
            }
            $byUnit[$answer['unit']][] = $answer;
        }

        $rows = [];
        foreach ($manifest->units() as $unit) {
            $list = $byUnit[$unit['code']] ?? [];
            $errors = [];
            foreach ($list as $a) {
                if (null !== $a['misconception']) {
                    $errors[$a['misconception']] = ($errors[$a['misconception']] ?? 0) + 1;
                }
            }
            arsort($errors);
            $template = $source->current($unit['code']);
            $named = [];
            foreach (\array_slice($errors, 0, 3, true) as $id => $count) {
                $named[$template?->misconceptionLabel((string) $id) ?? (string) $id] = $count;
            }
            $rows[] = [
                'code' => $unit['code'],
                'label' => $unit['label'],
                'course' => $unit['chapter'],
                'players' => \count(array_unique(array_column($list, 'player'))),
                'answers' => \count($list),
                'success' => [] === $list ? null : (float) \count(array_filter($list, static fn (array $a): bool => $a['ok'])) / \count($list),
                'errors' => $named,
            ];
        }

        return $rows;
    }

    /**
     * @param list<User>                                                                                               $students
     * @param array<int, list<int>>                                                                                    $moments
     * @param list<array{player: int, session: int, mode: string, unit: ?string, at: int, endedAt: ?int, score: ?int}> $sessions
     * @param array<int, array<string, SeriousGameUnitState>>                                                          $states
     *
     * @return list<array<string, mixed>>
     */
    private function studentRows(SeriousGame $game, array $students, array $moments, array $sessions, array $states): array
    {
        $manifest = SeriousGamePlay::manifest($game);
        $scenario = $game->isScenario() ? SeriousGameScenarioPlay::scenario($game) : null;
        $played = array_flip(array_column($sessions, 'player'));
        $rows = [];
        foreach ($students as $student) {
            $id = (int) $student->getId();
            $playerStates = $states[$id] ?? [];
            $chapter = null;
            $succeeded = 0;
            foreach ($manifest->chapters() as $c) {
                foreach ($c['units'] as $unit) {
                    $state = $playerStates[$unit['code']] ?? null;
                    if (null === $state) {
                        continue;
                    }
                    $reached = null !== $scenario ? ($state->isOnTheBoard() || null !== $state->getBest()) : $state->getSeriesCount() > 0;
                    $chapter = $reached ? $c['label'] : $chapter;
                    $succeeded += (null !== $scenario ? null !== $state->getBest() : AcquisitionRules::isLit($state->getBest(), $manifest->litAt())) ? 1 : 0;
                }
            }
            $list = $moments[$id] ?? [];
            $rows[] = [
                'student' => $student,
                'played' => isset($played[$id]),
                'lastActivity' => [] === $list ? null : (new \DateTimeImmutable('@'.max($list)))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
                'seconds' => PlayStats::playSeconds($list),
                'chapter' => $chapter,
                'succeeded' => $succeeded,
            ];
        }

        return $rows;
    }

    /**
     * The students who have played the game, establishment-wide - adults left out.
     *
     * @return list<User>
     */
    private function playersOf(SeriousGame $game): array
    {
        /** @var list<SeriousGameProgress> $progress */
        $progress = $this->entityManager->createQueryBuilder()
            ->select('p', 'u')
            ->from(SeriousGameProgress::class, 'p')
            ->innerJoin('p.player', 'u')
            ->andWhere('p.game = :game')
            ->setParameter('game', $game)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(array_map(static fn (SeriousGameProgress $p): User => $p->getPlayer(), $progress), static fn (User $u): bool => SeriousGameRules::canCollect($u->getRoles())));
    }

    /** @return list<User> the class's students who collect, by name */
    private static function studentsOf(Program $program): array
    {
        /** @var list<User> $students */
        $students = array_values(array_filter($program->getStudents()->toArray(), static fn (User $u): bool => SeriousGameRules::canCollect($u->getRoles())));
        usort($students, static fn (User $a, User $b): int => [mb_strtolower((string) $a->getLastname()), mb_strtolower((string) $a->getFirstname())] <=> [mb_strtolower((string) $b->getLastname()), mb_strtolower((string) $b->getFirstname())]);

        return $students;
    }

    /**
     * @param list<int> $players
     *
     * @return list<array{player: int, session: int, mode: string, unit: ?string, at: int, endedAt: ?int, score: ?int}>
     */
    private function sessions(SeriousGame $game, array $players, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        if ([] === $players) {
            return [];
        }
        $qb = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(s.player) AS player', 's.id', 's.mode', 's.unitCode', 's.startedAt', 's.endedAt', 's.score')
            ->from(SeriousGameSession::class, 's')
            ->andWhere('s.game = :game')
            ->andWhere('s.player IN (:players)')
            ->setParameter('game', $game)
            ->setParameter('players', $players);
        self::between($qb, 's.startedAt', $from, $to);

        /** @var list<array{player: int|string, id: int|string, mode: SeriousGameSessionMode, unitCode: ?string, startedAt: \DateTimeImmutable, endedAt: ?\DateTimeImmutable, score: int|string|null}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static fn (array $r): array => [
            'player' => (int) $r['player'],
            'session' => (int) $r['id'],
            'mode' => $r['mode']->value,
            'unit' => $r['unitCode'],
            'at' => $r['startedAt']->getTimestamp(),
            'endedAt' => $r['endedAt']?->getTimestamp(),
            'score' => null === $r['score'] ? null : (int) $r['score'],
        ], $rows);
    }

    /**
     * @param list<int> $players
     *
     * @return list<AnswerRow>
     */
    private function answers(SeriousGame $game, array $players, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        if ([] === $players) {
            return [];
        }
        $qb = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(s.player) AS player', 's.id AS session', 's.mode', 'a.unitCode', 'a.stepId', 'a.help', 'a.ok', 'a.misconception', 'a.input', 'a.answeredAt')
            ->from(SeriousGameAnswer::class, 'a')
            ->innerJoin('a.session', 's')
            ->andWhere('s.game = :game')
            ->andWhere('s.player IN (:players)')
            ->andWhere('a.answeredAt IS NOT NULL')
            ->setParameter('game', $game)
            ->setParameter('players', $players)
            ->orderBy('a.answeredAt', 'ASC')
            ->addOrderBy('a.id', 'ASC');
        self::between($qb, 'a.answeredAt', $from, $to);

        /** @var list<array{player: int|string, session: int|string, mode: SeriousGameSessionMode, unitCode: string, stepId: ?string, help: bool|int|string, ok: bool|int|string|null, misconception: ?string, input: ?string, answeredAt: \DateTimeImmutable}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static fn (array $r): array => [
            'player' => (int) $r['player'],
            'session' => (int) $r['session'],
            'mode' => $r['mode']->value,
            'unit' => $r['unitCode'],
            'stepId' => $r['stepId'],
            'help' => (bool) $r['help'],
            'ok' => (bool) $r['ok'],
            'misconception' => $r['misconception'],
            'input' => $r['input'],
            'at' => $r['answeredAt']->getTimestamp(),
        ], $rows);
    }

    /**
     * Where the players stand today - progress is not dated, so it is read whole.
     *
     * @param list<int> $players
     *
     * @return array<int, array<string, SeriousGameUnitState>>
     */
    private function states(SeriousGame $game, array $players): array
    {
        if ([] === $players) {
            return [];
        }
        /** @var list<SeriousGameUnitState> $states */
        $states = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(SeriousGameUnitState::class, 's')
            ->andWhere('s.game = :game')
            ->andWhere('s.player IN (:players)')
            ->setParameter('game', $game)
            ->setParameter('players', $players)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($states as $state) {
            $out[(int) $state->getPlayer()->getId()][$state->getUnitCode()] = $state;
        }

        return $out;
    }

    private static function between(\Doctrine\ORM\QueryBuilder $qb, string $field, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): void
    {
        if (null !== $from) {
            $qb->andWhere($field.' >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            $qb->andWhere($field.' < :to')->setParameter('to', $to);
        }
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} a school year's first day and the day after its last */
    private static function window(?SchoolYear $year): array
    {
        $start = $year?->getStartDate();
        $end = $year?->getEndDate();

        return [$start?->setTime(0, 0), $end?->setTime(0, 0)->modify('+1 day')];
    }
}
