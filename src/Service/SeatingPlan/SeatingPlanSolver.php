<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Proposes who sits where (design/validated/plans-de-classe.md, §6).
 *
 * A simulated annealing over swaps: the class is dealt onto the free seats at random, then two
 * places are swapped again and again, a swap kept when it does not make the plan worse - and, while
 * the temperature is high, sometimes even when it does, so that the search does not settle in the
 * first arrangement where no single swap helps. The plan kept is the best one met.
 *
 * - **Locked seats are not dealt**: their student stays where the teacher put them.
 * - **The seed decides everything**: the same document and seed give the same plan, which is what
 *   lets « Proposer un autre plan » be a seed + 1 and a test name a result.
 * - A class larger than the room leaves students without a seat; who they are is part of the
 *   search (a rule's student is worth a seat more than another).
 * - It stops early once nothing is left to improve.
 */
final class SeatingPlanSolver
{
    private const int ITERATIONS = 6000;

    public function __construct(
        private readonly SeatingRuleChecker $checker,
    ) {
    }

    /**
     * @param array<int, SeatingStudent> $roster keyed by student id
     *
     * @return array<string, int> seat id => student id
     */
    public function propose(SeatingPlanDocument $document, array $roster, int $seed): array
    {
        $randomizer = new Randomizer(new Mt19937($seed));
        $room = $document->room;
        $options = array_map(static fn (SeatingStudent $student): ?string => $student->option, $roster);

        $seats = [];
        $pinned = [];
        foreach ($document->pins as $pin) {
            $studentId = $document->seats[$pin] ?? null;
            if (null !== $studentId && isset($roster[$studentId]) && null !== $room->seat($pin)) {
                $seats[$pin] = $studentId;
                $pinned[$studentId] = true;
            }
        }

        $freeSeats = [];
        foreach ($room->seats() as $seat) {
            if (!isset($seats[$seat->id])) {
                $freeSeats[] = $seat->id;
            }
        }
        $freeStudents = array_values(array_filter(array_keys($roster), static fn (int $id): bool => !isset($pinned[$id])));
        $freeStudents = [] === $freeStudents ? [] : $randomizer->shuffleArray($freeStudents);

        // One slot per free seat, then the students left without one: swapping a seat with one of
        // those slots is how the search decides who stands out.
        $slotCount = max(\count($freeSeats), \count($freeStudents));
        $values = [];
        for ($i = 0; $i < $slotCount; ++$i) {
            $values[] = $freeStudents[$i] ?? null;
        }
        $seatCount = \count($freeSeats);

        $build = static function (array $values) use ($seats, $freeSeats, $seatCount): array {
            $result = $seats;
            for ($i = 0; $i < $seatCount; ++$i) {
                if (null !== $values[$i]) {
                    $result[$freeSeats[$i]] = $values[$i];
                }
            }

            return $result;
        };
        $score = fn (array $values): int => $this->checker->penalty($room, $document->rules, $document->mixOptions, $document->frontFirst, $build($values), $options);

        $current = $score($values);
        $best = $current;
        $bestValues = $values;
        $temperature = 60.0;

        for ($iteration = 0; $iteration < self::ITERATIONS && $best > 0 && $slotCount > 1; ++$iteration) {
            $i = $randomizer->getInt(0, $slotCount - 1);
            $j = $randomizer->getInt(0, $slotCount - 1);
            if ($i === $j || $values[$i] === $values[$j] || ($i >= $seatCount && $j >= $seatCount)) {
                continue;
            }

            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
            $candidate = $score($values);
            $delta = $candidate - $current;
            if ($delta <= 0 || $randomizer->nextFloat() < exp(-$delta / $temperature)) {
                $current = $candidate;
                if ($candidate < $best) {
                    $best = $candidate;
                    $bestValues = $values;
                }
            } else {
                [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
            }
            $temperature = max(0.5, $temperature * 0.9993);
        }

        return $build($bestValues);
    }
}
