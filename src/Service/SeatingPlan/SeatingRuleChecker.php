<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

use App\Enum\SeatingRuleType;

/**
 * What each placement rule means, and whether a plan keeps it (design/validated/plans-de-classe.md,
 * §4). The one reading of the rules: the screen's checklist, the red seats and the solver's
 * penalty all come from here.
 *
 * - **Séparer**: no two of them near each other - side by side, front/back or diagonally.
 * - **Associer**: each of them beside another of them - the same table, or touching on one row.
 * - **Devant**: in the first two ranks that hold a seat. **Au fond**: in the last.
 * - **Isoler**: nobody else at their table.
 * - The preferences: no two students of the same option at one table; no empty seat in front of
 *   a seated student.
 *
 * A rule is a requirement and a preference a wish: a broken rule costs far more than the worst
 * preference, so the solver gives up on preferences first. A rule's student without a seat breaks
 * it - « placer devant » is not kept by leaving the student out.
 */
final class SeatingRuleChecker
{
    private const int RULE_WEIGHT = 100;
    private const int MISSING_WEIGHT = 40;
    private const int SAME_OPTION_WEIGHT = 4;
    private const int EMPTY_AHEAD_WEIGHT = 5;

    /**
     * @param array<int, SeatingStudent> $roster keyed by student id
     */
    public function check(SeatingPlanDocument $document, array $roster): SeatingCheck
    {
        $options = array_map(static fn (SeatingStudent $student): ?string => $student->option, $roster);

        return $this->evaluate($document->room, $document->rules, $document->mixOptions, $document->frontFirst, $document->seats, $options, $roster, true);
    }

    /**
     * The penalty alone, for the solver - the same evaluation, without the sentences.
     *
     * @param list<SeatingRule>       $rules
     * @param array<string, int>      $seats
     * @param array<int, string|null> $options
     */
    public function penalty(SeatingRoom $room, array $rules, bool $mixOptions, bool $frontFirst, array $seats, array $options): int
    {
        return $this->evaluate($room, $rules, $mixOptions, $frontFirst, $seats, $options, [], false)->penalty;
    }

    /**
     * @param list<SeatingRule>          $rules
     * @param array<string, int>         $seats
     * @param array<int, string|null>    $options
     * @param array<int, SeatingStudent> $roster
     */
    private function evaluate(SeatingRoom $room, array $rules, bool $mixOptions, bool $frontFirst, array $seats, array $options, array $roster, bool $detail): SeatingCheck
    {
        /** @var array<int, SeatingSeat> $position */
        $position = [];
        /** @var array<string, list<int>> $byTable */
        $byTable = [];
        foreach ($seats as $seatId => $studentId) {
            $seat = $room->seat($seatId);
            if (null !== $seat) {
                $position[$studentId] = $seat;
                $byTable[$seat->tableId][] = $studentId;
            }
        }

        $name = static fn (int $id): string => $roster[$id]->shortName ?? '?';
        $penalty = 0;
        $results = [];
        $faulty = [];
        $last = $room->lastRank();

        foreach ($rules as $rule) {
            $problems = [];
            $placed = [];
            foreach ($rule->studentIds as $studentId) {
                if (isset($position[$studentId])) {
                    $placed[] = $studentId;
                } else {
                    $penalty += self::MISSING_WEIGHT;
                    if ($detail) {
                        $problems[] = new SeatingProblem('seatingCheckNoSeat', ['%student%' => $name($studentId)]);
                    }
                }
            }

            switch ($rule->type) {
                case SeatingRuleType::Separate:
                    $count = \count($placed);
                    for ($i = 0; $i < $count; ++$i) {
                        for ($j = $i + 1; $j < $count; ++$j) {
                            $a = $position[$placed[$i]];
                            $b = $position[$placed[$j]];
                            if ($a->isNear($b)) {
                                $penalty += self::RULE_WEIGHT;
                                if ($detail) {
                                    $problems[] = new SeatingProblem('seatingCheckNeighbours', ['%a%' => $name($placed[$i]), '%b%' => $name($placed[$j])]);
                                    $faulty[$a->id] = true;
                                    $faulty[$b->id] = true;
                                }
                            }
                        }
                    }
                    break;

                case SeatingRuleType::Together:
                    $alone = [];
                    foreach ($placed as $a) {
                        $nearest = \PHP_INT_MAX;
                        $beside = false;
                        foreach ($placed as $b) {
                            if ($a === $b) {
                                continue;
                            }
                            if ($position[$a]->isBeside($position[$b])) {
                                $beside = true;
                                break;
                            }
                            $nearest = min($nearest, $position[$a]->distanceTo($position[$b]));
                        }
                        if (!$beside && \count($placed) > 1) {
                            $alone[] = $a;
                            // The distance gives the solver a slope to walk down: two students
                            // three seats apart are a better start than two at opposite walls.
                            $penalty += (int) (self::RULE_WEIGHT / 2) + 6 * $nearest;
                        }
                    }
                    if ($detail && [] !== $alone) {
                        foreach ($alone as $a) {
                            $faulty[$position[$a]->id] = true;
                        }
                        if (2 === \count($placed)) {
                            $problems[] = new SeatingProblem('seatingCheckNotTogether', ['%a%' => $name($placed[0]), '%b%' => $name($placed[1])]);
                        } else {
                            foreach ($alone as $a) {
                                $problems[] = new SeatingProblem('seatingCheckFarFromGroup', ['%student%' => $name($a)]);
                            }
                        }
                    }
                    break;

                case SeatingRuleType::Front:
                    foreach ($placed as $a) {
                        $rank = $room->rankOf($position[$a]);
                        if ($rank > 1) {
                            $penalty += 60 + 25 * ($rank - 1);
                            if ($detail) {
                                $problems[] = new SeatingProblem('seatingCheckRank', ['%student%' => $name($a), '%rank%' => $rank + 1]);
                                $faulty[$position[$a]->id] = true;
                            }
                        }
                    }
                    break;

                case SeatingRuleType::Back:
                    foreach ($placed as $a) {
                        $rank = $room->rankOf($position[$a]);
                        if ($rank < $last) {
                            $penalty += 60 + 25 * ($last - $rank - 1);
                            if ($detail) {
                                $problems[] = new SeatingProblem('seatingCheckNotBack', ['%student%' => $name($a), '%rank%' => $rank + 1, '%ranks%' => $last + 1]);
                                $faulty[$position[$a]->id] = true;
                            }
                        }
                    }
                    break;

                case SeatingRuleType::Alone:
                    foreach ($placed as $a) {
                        $others = \count($byTable[$position[$a]->tableId] ?? []) - 1;
                        if ($others > 0) {
                            $penalty += self::RULE_WEIGHT * $others;
                            if ($detail) {
                                $problems[] = new SeatingProblem('seatingCheckSharesTable', ['%student%' => $name($a)]);
                                $faulty[$position[$a]->id] = true;
                            }
                        }
                    }
                    break;
            }

            if ($detail) {
                $results[] = new SeatingRuleResult($rule, null, [] === $problems, $problems);
            }
        }

        if ($mixOptions) {
            $same = 0;
            foreach ($byTable as $students) {
                $count = \count($students);
                for ($i = 0; $i < $count; ++$i) {
                    for ($j = $i + 1; $j < $count; ++$j) {
                        $a = $options[$students[$i]] ?? null;
                        if (null !== $a && $a === ($options[$students[$j]] ?? null)) {
                            ++$same;
                        }
                    }
                }
            }
            $penalty += self::SAME_OPTION_WEIGHT * $same;
            if ($detail) {
                $results[] = new SeatingRuleResult(null, SeatingRuleResult::PREFERENCE_MIX, 0 === $same, 0 === $same ? [] : [new SeatingProblem('seatingCheckSameOption', ['%count%' => $same])]);
            }
        }

        if ($frontFirst) {
            $deepest = -1;
            $empties = [];
            foreach ($room->seats() as $seat) {
                if (isset($seats[$seat->id])) {
                    $deepest = max($deepest, $room->rankOf($seat));
                } else {
                    $empties[] = $seat;
                }
            }
            $ahead = 0;
            foreach ($empties as $seat) {
                $rank = $room->rankOf($seat);
                if ($rank < $deepest) {
                    ++$ahead;
                    $penalty += self::EMPTY_AHEAD_WEIGHT * ($deepest - $rank);
                }
            }
            // Said only when the room has more seats than the class: otherwise there is no empty
            // seat to put anywhere, and a ticked line would claim a choice nobody made.
            if ($detail && [] !== $empties) {
                $results[] = new SeatingRuleResult(null, SeatingRuleResult::PREFERENCE_FRONT, 0 === $ahead, 0 === $ahead ? [] : [new SeatingProblem('seatingCheckEmptyAhead', ['%count%' => $ahead])]);
            }
        }

        return new SeatingCheck($penalty, $results, array_keys($faulty));
    }
}
