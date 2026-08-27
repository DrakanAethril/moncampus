<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\GameMonthScore;
use App\Entity\GameProfile;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\GameEntryRepository;
use App\Repository\GameMonthScoreRepository;
use App\Repository\GameProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Closing a month - the one moment the game writes something that is never recomputed.
 *
 * A month, not an evaluation period: points are credited on the day they are earned and counted in
 * the month that day falls in, and a month is the same for everybody and needs no setting up. A
 * formation only ranks the months it ticked (App\Entity\GameProgramSettings::$rankedMonths), so a
 * school can leave July and August out without leaving a hole anywhere.
 *
 * The order, and what each step is for:
 *
 * 1. **Collect** what the sources have to say, one last time - a submission made on the last evening
 *    has to count.
 * 2. **Freeze** one App\Entity\GameMonthScore per student: the index, the four rates, the rank.
 *    Written once, because the barème is adjustable and September's ranking must not move in June.
 * 3. **Pay the top three** - 20, 10 and 5 points of recognition. They are ordinary ledger lines,
 *    dated on the last day of the month they reward, so they read in the right place in a journal
 *    and count towards the year exactly like everything else.
 * 4. **Update the profile**: the running total of everything the student has ever earned, and the
 *    level that total gives them. Neither is scoped to a formation - a student keeps their points
 *    for the whole of their schooling, and moving up a year starts them where they already are.
 * 5. **Grant the level frames** that total has just opened.
 *
 * Idempotent throughout: a month already frozen is skipped whole, and every write is either bounded
 * by that snapshot or refused by the ledger's own duplicate check.
 */
final class GameMonthCloser
{
    /** What the first three of a month are paid, in order. */
    public const array PODIUM = [20, 10, 5];

    public function __construct(
        private readonly GameIndexReader $reader,
        private readonly GameCollector $collector,
        private readonly GameLedger $ledger,
        private readonly GameLevelResolver $levels,
        private readonly GameMonthScoreRepository $scores,
        private readonly GameProfileRepository $profiles,
        private readonly GameEntryRepository $entries,
        private readonly GameSettingsProvider $settings,
        private readonly RewardGranter $rewards,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return int how many students were frozen; 0 when the month was already closed, is not over,
     *             or is not one this formation ranks
     */
    public function close(Program $program, GameMonth $month, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        if (!$month->hasEnded($now) || $this->scores->isClosed($program, $month->key())) {
            return 0;
        }

        if (!$this->settings->for($program)->ranksMonth($month->month)) {
            return 0;
        }

        $students = array_values($program->getStudents()->toArray());

        if ([] === $students) {
            return 0;
        }

        $from = $month->firstDay();
        $to = $month->lastMoment();

        foreach ($students as $student) {
            $this->collector->collectWithoutFlush($student, $program, $from, $to, $now);
        }
        $this->entityManager->flush();

        $standings = $this->reader->standingsFor($students, $program, $from, $to, $now);
        $profiles = $this->profiles->findForStudents($students);

        $ranked = [];
        foreach ($students as $student) {
            $id = (int) $student->getId();
            $profile = $profiles[$id] ?? new GameProfile($student);

            if (!isset($profiles[$id])) {
                $this->entityManager->persist($profile);
                $profiles[$id] = $profile;
            }

            $standing = $standings[$id];

            $score = new GameMonthScore($student, $program, $month->key(), $standing->index());
            $score->setRates($standing->score->rates);
            $this->entityManager->persist($score);

            // A student in discreet mode is scored and rewarded like everybody else and simply
            // carries no rank - which is what `rank` being nullable is for.
            if (!$profile->isDiscreet()) {
                $ranked[] = $score;
            }
        }

        usort($ranked, static fn (GameMonthScore $a, GameMonthScore $b): int => $b->getIndexValue() <=> $a->getIndexValue());

        foreach ($ranked as $position => $score) {
            $score->setRank($position + 1);

            $bonus = self::PODIUM[$position] ?? 0;

            if ($bonus > 0) {
                $score->setBonusAwarded($bonus);
                $this->ledger->record(
                    $score->getStudent(),
                    $program,
                    GameRuleCatalog::RECOGNITION_MONTH_PODIUM,
                    'GameMonthScore',
                    // Keyed on the month rather than on the row, so the line is written once
                    // however many times a closure is retried.
                    (int) str_replace('-', '', $month->key()),
                    $to,
                    $bonus,
                );
            }
        }

        $this->entityManager->flush();

        $this->refreshProfiles($students, $profiles, $program);

        $this->entityManager->flush();

        return \count($students);
    }

    /** Every ranked month of a formation that has ended and is not closed yet, oldest first. */
    public function pendingMonths(Program $program, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $settings = $this->settings->for($program);

        // Twelve months back is plenty: a formation nobody has closed for a year has a bigger
        // problem than a missing bonus, and an unbounded walk would scan the whole calendar.
        $month = GameMonth::of($now)->previous();
        $pending = [];

        for ($i = 0; $i < 12; ++$i) {
            if ($settings->ranksMonth($month->month) && !$this->scores->isClosed($program, $month->key())) {
                $pending[] = $month;
            }

            $month = $month->previous();
        }

        return array_reverse($pending);
    }

    /**
     * The running total and the level it gives, across **every** formation the student has passed
     * through, plus the frames that total has opened.
     *
     * @param list<User>              $students
     * @param array<int, GameProfile> $profiles
     */
    private function refreshProfiles(array $students, array $profiles, Program $program): void
    {
        foreach ($students as $student) {
            $profile = $profiles[(int) $student->getId()] ?? null;

            if (null === $profile) {
                continue;
            }

            $total = $this->entries->sumForStudent($student);
            $profile->setTotalPoints($total);
            $profile->setLevel($this->levels->resolve($total)->level->level);

            $this->rewards->grantLevelFrames($student, $program, $profile->getLevel());
        }
    }
}
