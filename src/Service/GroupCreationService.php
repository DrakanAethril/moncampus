<?php

namespace App\Service;

use App\Enum\GroupCreationMode;
use App\Enum\GroupMixite;

/**
 * Server-side placement algorithm for the "Création de groupes" tool (design/
 * design_campus_manager/PROMPT_CLAUDE_CODE_groupes.md, section 4 - "Algorithme (backend)").
 *
 * Pipeline: merge "réunir" pairs into atomic units (union-find) - a unit always moves as one -
 * then repeatedly shuffle the units and place them into the open (non-locked) groups, preferring
 * a group under the target capacity and never one that would put a "séparer" pair together. If no
 * legal group exists for a unit, that whole attempt is discarded and retried with a fresh shuffle
 * (up to self::MAX_ATTEMPTS times) rather than silently placing it in a conflicting group -
 * "séparer" is a hard constraint, unlike capacity (soft: exceeded only when every open group is
 * already full, which is how an indivisible pool count ends up "spread evenly, off by at most
 * one" - design's acceptance criterion 1 - without ever blocking a valid placement over it) and
 * mixité (soft: a same/opposite-option placement preference among otherwise-legal candidates).
 *
 * Also used for "Rebrasser les groupes déverrouillés": the caller passes $existingGroups with the
 * locked slots already filled (and excluded via $lockedIndices) and everyone else already
 * stripped out of $remainingPool - this class never needs to know which run produced which.
 */
class GroupCreationService
{
    private const int MAX_ATTEMPTS = 200;

    /**
     * @param list<list<array{id: int, optionId: ?int}>> $existingGroups current groups, in order -
     *        entries at a $lockedIndices position are preserved as-is; all others are expected
     *        empty and get filled
     * @param list<int>                                  $lockedIndices  positions in
     *        $existingGroups that must not be touched
     * @param list<array{id: int, optionId: ?int}>        $remainingPool  students to place this
     *        round - must exclude anyone already sitting in a locked group
     * @param int                                         $totalScopedCount every non-absent
     *        student in the current Option scope, including those already in locked groups - what
     *        GroupCreationMode::Count's capacity is divided across
     * @param list<array{0: int, 1: int}>                 $separatePairs  student id pairs that
     *        must never share a group
     * @param list<array{0: int, 1: int}>                 $togetherPairs  student id pairs that
     *        must always share a group
     *
     * @return list<list<array{id: int, optionId: ?int}>> same shape as $existingGroups, every
     *         slot now filled
     *
     * @throws UnsatisfiableGroupConstraintsException
     */
    public function createGroups(
        array $existingGroups,
        array $lockedIndices,
        array $remainingPool,
        int $totalScopedCount,
        GroupCreationMode $mode,
        int $value,
        GroupMixite $mixite,
        array $separatePairs,
        array $togetherPairs,
    ): array {
        $groupCount = \count($existingGroups);
        if (0 === $groupCount) {
            return [];
        }

        $units = $this->buildUnits($remainingPool, $togetherPairs);
        $separateIndex = $this->buildSeparateIndex($separatePairs);
        $this->assertNoSeparateWithinUnit($units, $separateIndex);

        $capacity = GroupCreationMode::Size === $mode ? $value : (int) ceil($totalScopedCount / $groupCount);
        $this->assertUnitsFitCapacity($units, $capacity);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $result = $this->tryPlace($existingGroups, $lockedIndices, $units, $capacity, $mixite, $separateIndex);
            if (null !== $result) {
                return $result;
            }
        }

        throw new UnsatisfiableGroupConstraintsException('Impossible de satisfaire les contraintes « séparer » avec ces paramètres.');
    }

    /**
     * Union-find over $togetherPairs - everyone not pulled into a "réunir" cluster stays their own
     * singleton unit.
     *
     * @param list<array{id: int, optionId: ?int}> $pool
     * @param list<array{0: int, 1: int}>          $togetherPairs
     *
     * @return list<list<array{id: int, optionId: ?int}>>
     */
    private function buildUnits(array $pool, array $togetherPairs): array
    {
        $byId = [];
        foreach ($pool as $student) {
            $byId[$student['id']] = $student;
        }

        $parent = array_combine(array_keys($byId), array_keys($byId));

        foreach ($togetherPairs as [$a, $b]) {
            if (!isset($byId[$a], $byId[$b])) {
                // References a student outside this round's pool (absent, filtered out by the
                // Option scope, or already sitting in a locked group) - nothing to merge.
                continue;
            }

            $rootA = $this->find($parent, $a);
            $rootB = $this->find($parent, $b);
            if ($rootA !== $rootB) {
                $parent[$rootB] = $rootA;
            }
        }

        $clusters = [];
        foreach ($byId as $id => $student) {
            $clusters[$this->find($parent, $id)][] = $student;
        }

        return array_values($clusters);
    }

    /** @param array<int, int> $parent */
    private function find(array &$parent, int $id): int
    {
        if ($parent[$id] !== $id) {
            $parent[$id] = $this->find($parent, $parent[$id]);
        }

        return $parent[$id];
    }

    /**
     * @param list<array{0: int, 1: int}> $separatePairs
     *
     * @return array<int, array<int, true>> id => set of ids it must never share a group with
     */
    private function buildSeparateIndex(array $separatePairs): array
    {
        $index = [];
        foreach ($separatePairs as [$a, $b]) {
            $index[$a][$b] = true;
            $index[$b][$a] = true;
        }

        return $index;
    }

    /**
     * @param list<list<array{id: int, optionId: ?int}>> $units
     * @param array<int, array<int, true>>                $separateIndex
     */
    private function assertNoSeparateWithinUnit(array $units, array $separateIndex): void
    {
        foreach ($units as $unit) {
            foreach ($unit as $a) {
                foreach ($unit as $b) {
                    if ($a['id'] !== $b['id'] && isset($separateIndex[$a['id']][$b['id']])) {
                        // A "réunir" cluster (possibly transitive - A~B and B~C makes A~C) also
                        // carries a "séparer" between two of its own members - can never be
                        // satisfied simultaneously.
                        throw new UnsatisfiableGroupConstraintsException('Deux élèves sont à la fois « à réunir » et « à séparer » (directement ou via une chaîne de paires).');
                    }
                }
            }
        }
    }

    /** @param list<list<array{id: int, optionId: ?int}>> $units */
    private function assertUnitsFitCapacity(array $units, int $capacity): void
    {
        foreach ($units as $unit) {
            if (\count($unit) > $capacity) {
                throw new UnsatisfiableGroupConstraintsException('Un groupe « à réunir » est plus grand que la capacité d\'un groupe.');
            }
        }
    }

    /**
     * One shuffle-and-place attempt. Returns null (never a partial/violating result) if some unit
     * has no legal open group left - the caller retries with a fresh shuffle.
     *
     * @param list<list<array{id: int, optionId: ?int}>> $existingGroups
     * @param list<int>                                   $lockedIndices
     * @param list<list<array{id: int, optionId: ?int}>>  $units
     * @param array<int, array<int, true>>                $separateIndex
     *
     * @return list<list<array{id: int, optionId: ?int}>>|null
     */
    private function tryPlace(array $existingGroups, array $lockedIndices, array $units, int $capacity, GroupMixite $mixite, array $separateIndex): ?array
    {
        $groups = $existingGroups;
        $lockedLookup = array_fill_keys($lockedIndices, true);

        $shuffled = $units;
        shuffle($shuffled);
        if (GroupMixite::Homogeneous === $mixite) {
            usort($shuffled, static fn (array $a, array $b): int => ($a[0]['optionId'] ?? -1) <=> ($b[0]['optionId'] ?? -1));
        }

        foreach ($shuffled as $unit) {
            $openIndices = array_values(array_filter(array_keys($groups), static fn (int $i): bool => !isset($lockedLookup[$i])));
            if ([] === $openIndices) {
                return null;
            }

            $unitSize = \count($unit);
            $notFull = array_values(array_filter($openIndices, static fn (int $i): bool => \count($groups[$i]) + $unitSize <= $capacity));
            $candidates = [] !== $notFull ? $notFull : $openIndices;

            $legal = array_values(array_filter($candidates, fn (int $i): bool => !$this->conflicts($unit, $groups[$i], $separateIndex)));
            if ([] === $legal) {
                return null;
            }

            $chosen = $this->pickBestCandidate($legal, $groups, $unit, $mixite);
            array_push($groups[$chosen], ...$unit);
        }

        return $groups;
    }

    /**
     * @param list<array{id: int, optionId: ?int}> $unit
     * @param list<array{id: int, optionId: ?int}> $group
     * @param array<int, array<int, true>>          $separateIndex
     */
    private function conflicts(array $unit, array $group, array $separateIndex): bool
    {
        foreach ($unit as $u) {
            if (!isset($separateIndex[$u['id']])) {
                continue;
            }
            foreach ($group as $m) {
                if (isset($separateIndex[$u['id']][$m['id']])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<int>                                   $candidateIndices
     * @param list<list<array{id: int, optionId: ?int}>>  $groups
     * @param list<array{id: int, optionId: ?int}>         $unit
     */
    private function pickBestCandidate(array $candidateIndices, array $groups, array $unit, GroupMixite $mixite): int
    {
        $unitOption = $unit[0]['optionId'];

        $scored = array_map(function (int $i) use ($groups, $unitOption, $mixite): array {
            $group = $groups[$i];
            $score = match ($mixite) {
                // Prefer the group already holding the fewest members of this option, to spread
                // it out.
                GroupMixite::Mixed => \count(array_filter($group, static fn (array $m): bool => $m['optionId'] === $unitOption)),
                // Prefer a group that's already 100% this option (0), then an empty group (0.5),
                // then a mixed one last (1) - clusters same-option students together over time as
                // units get pre-sorted by option before placement.
                GroupMixite::Homogeneous => match (true) {
                    [] === $group => 0.5,
                    $this->allSameOption($group, $unitOption) => 0.0,
                    default => 1.0,
                },
                GroupMixite::Free => 0.0,
            };

            return ['index' => $i, 'score' => $score, 'size' => \count($group)];
        }, $candidateIndices);

        usort($scored, static fn (array $a, array $b): int => $a['score'] <=> $b['score'] ?: $a['size'] <=> $b['size']);

        return $scored[0]['index'];
    }

    /** @param list<array{id: int, optionId: ?int}> $group */
    private function allSameOption(array $group, ?int $optionId): bool
    {
        foreach ($group as $member) {
            if ($member['optionId'] !== $optionId) {
                return false;
            }
        }

        return true;
    }
}
