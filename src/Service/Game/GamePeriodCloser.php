<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EvaluationPeriod;
use App\Entity\GamePeriodScore;
use App\Entity\GameProfile;
use App\Entity\Program;
use App\Entity\RewardGrant;
use App\Entity\User;
use App\Repository\GamePeriodScoreRepository;
use App\Repository\GameProfileRepository;
use App\Repository\RewardItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Closing a period - the one moment the game writes something that is never recomputed.
 *
 * The order matters and each step says why:
 *
 * 1. **Collect** what the sources have to say, one last time. A submission made on the last evening
 *    has to count.
 * 2. **Pay the team objective**, then and only then. It is recognition points, so it feeds the index
 *    - which means the indices have to be read once to know which teams cleared the threshold, the
 *    points written, and the indices read again. Paying it after the freeze would pay points nobody
 *    could ever see.
 * 3. **Freeze** App\Entity\GamePeriodScore. Written once and never recomputed: the barème is
 *    adjustable, so if a closed period recomputed itself under today's rules, January's ranking
 *    would move in June and the rewards granted on it would become arguable.
 * 4. **Pay the XP**, on the cursus formula - `indice x (3600 / nombre de périodes) / 100`, so a
 *    perfect cursus is always 3 600 XP whatever the calendar. XP only ever grows.
 * 5. **Grant the tiers**, on the index and never on a total, plus the promotion trophy to the head
 *    of the class.
 * 6. **Close the attendance statements**, which stops the term's own ranking from moving afterwards.
 * 7. **Open the next period**: lower the discretion of whoever asked to come back, and draw the new
 *    aliases.
 *
 * Idempotent throughout: a period already frozen is skipped whole, and every write below is either
 * guarded by the frozen snapshot or refused by the ledger's own duplicate check.
 */
final class GamePeriodCloser
{
    public function __construct(
        private readonly GameIndexReader $reader,
        private readonly GameCollector $collector,
        private readonly GameLedger $ledger,
        private readonly GameLevelResolver $levels,
        private readonly GamePeriodResolver $periods,
        private readonly GamePeriodScoreRepository $scores,
        private readonly GameProfileRepository $profiles,
        private readonly GameTeamBoard $teams,
        private readonly RewardGranter $rewards,
        private readonly RewardItemRepository $rewardItems,
        private readonly GameStatementService $statements,
        private readonly GameAliasDrawer $aliases,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return int how many students were frozen; 0 when the period was already closed
     */
    public function close(Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $now = null): int
    {
        if ($this->scores->isClosed($program, $period)) {
            return 0;
        }

        $now ??= new \DateTimeImmutable();
        $students = array_values($program->getStudents()->toArray());

        if ([] === $students) {
            return 0;
        }

        foreach ($students as $student) {
            $this->collector->collectWithoutFlush($student, $program, $period, $now);
        }
        $this->entityManager->flush();

        $this->payTeamObjective($program, $period, $now);

        $frozen = $this->freeze($students, $program, $period, $now);

        // Every attendance relevé whose span has ended is closed with the period: what stops a
        // term's ranking from moving afterwards. A relevé still running is left alone - relevés no
        // longer belong to a period, so one may legitimately straddle two.
        $this->statements->closeAttendanceUpTo($program, $period->getEndDate() ?? $now);
        $this->openNext($program, $period);

        return $frozen;
    }

    /**
     * The collective threshold: if **every** member of a team finishes above it, each of them gains
     * recognition points.
     *
     * There is no first team and no last, and several may succeed together. A podium would reward
     * the team that drew the best students - the draw would decide - where a threshold everyone has
     * to clear makes helping the weakest member the rational move. It is the only place in the system
     * that produces mutual aid rather than comparison.
     */
    private function payTeamObjective(Program $program, EvaluationPeriod $period, \DateTimeImmutable $now): void
    {
        foreach ($this->teams->teams($program, $period, $now) as $team) {
            if (!$team->isReached()) {
                continue;
            }

            foreach ($team->members as $member) {
                $this->ledger->record(
                    $member['student'],
                    $program,
                    $period,
                    GameRuleCatalog::RECOGNITION_TEAM_GOAL,
                    'GameTeamSet',
                    // Keyed on the team's position so the source is stable and the line is written
                    // once, however many times a closure is retried.
                    $team->position,
                    $now,
                );
            }
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<User> $students
     */
    private function freeze(array $students, Program $program, EvaluationPeriod $period, \DateTimeImmutable $now): int
    {
        $standings = $this->reader->standingsFor($students, $program, $period, $now);
        $profiles = $this->profiles->findForStudents($students);
        $periodCount = $this->periods->periodCount($program);

        $ranked = [];
        foreach ($students as $student) {
            $id = (int) $student->getId();
            $profile = $profiles[$id] ?? new GameProfile($student);

            if (!isset($profiles[$id])) {
                $this->entityManager->persist($profile);
            }

            $standing = $standings[$id];
            $index = $standing->index();

            $score = new GamePeriodScore($student, $program, $period, $index);
            $score->setRates($standing->score->rates);
            $score->setXpAwarded($this->levels->xpForIndex($index, $periodCount));

            $profile->addXp($score->getXpAwarded());
            $profile->setLevel($this->levels->resolve($profile->getXpTotal())->level->level);

            $this->entityManager->persist($score);

            // A student in discreet mode is scored and rewarded like everybody else and simply
            // carries no rank - which is what `rank` being nullable is for.
            if (!$profile->isDiscreet()) {
                $ranked[] = $score;
            }
        }

        usort($ranked, static fn (GamePeriodScore $a, GamePeriodScore $b): int => $b->getIndexValue() <=> $a->getIndexValue());

        foreach ($ranked as $position => $score) {
            $score->setRank($position + 1);
        }

        $this->entityManager->flush();

        $this->grantTiers($program, $period, $students, $standings);
        $this->grantTrophy($program, $period, $ranked);

        $this->entityManager->flush();

        return \count($students);
    }

    /**
     * @param list<User>                $students
     * @param array<int, GameStanding>  $standings
     */
    private function grantTiers(Program $program, EvaluationPeriod $period, array $students, array $standings): void
    {
        foreach ($students as $student) {
            $index = $standings[(int) $student->getId()]->index();
            $this->rewards->grantAutomatic($student, $program, $period, $index);
        }
    }

    /** The promotion trophy: the head of the class, which is a rank rather than a value. */
    private function grantTrophy(Program $program, EvaluationPeriod $period, array $ranked): void
    {
        $top = $ranked[0] ?? null;
        $trophy = $this->rewardItems->findTier('trophy');

        if (null === $top || null === $trophy || null === $top->getStudent()->getId()) {
            return;
        }

        $this->entityManager->persist(
            (new RewardGrant($trophy, $program, $period))->setStudent($top->getStudent()),
        );
    }

    /**
     * The next period opens: whoever asked to come out of discreet mode does so now, and the new
     * aliases are drawn.
     *
     * The delay is the whole rule (§4, decision 8): leaving is immediate, returning waits for a
     * closure, or the switch becomes a tactical one and the ranking stops meaning anything.
     */
    private function openNext(Program $program, EvaluationPeriod $period): void
    {
        foreach ($this->profiles->findForStudents(array_values($program->getStudents()->toArray())) as $profile) {
            $profile->applyPendingReturn();
        }

        $this->entityManager->flush();

        $next = $this->periods->nextPeriod($program, $period);

        if (null !== $next) {
            $this->aliases->drawForClass($program, $next);
        }
    }
}
