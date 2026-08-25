<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AccessConditionMode;
use App\Enum\AccessConditionType;

/**
 * The access-condition rule itself: a pure function from a stored condition and a student's facts
 * to a verdict - see design/comparaison/conception_1_3_5.md, "Point 3".
 *
 * It has no dependencies at all, which is deliberate and is what the conception asks for: the
 * decision is made on identifiers, percentages and dates, so it can be written test-first and read
 * in one sitting. Everything expensive (loading the facts, naming the objects, short-circuiting a
 * teacher) sits in AccessConditionGate around it.
 *
 * Nothing is persisted here either - like StudentWorkBoard, a verdict is a reading at instant t, so
 * it is always current and never has to be maintained.
 */
class AccessConditionEvaluator
{
    public function evaluate(?AccessConditionTree $tree, StudentAccessFacts $facts): AccessConditionVerdict
    {
        if (null === $tree) {
            return AccessConditionVerdict::open();
        }

        $unmet = array_values(array_filter(
            $tree->leaves,
            fn (AccessConditionLeaf $leaf): bool => !$this->holds($leaf, $facts),
        ));

        if (AccessConditionMode::All === $tree->mode) {
            return new AccessConditionVerdict([] === $unmet, $unmet);
        }

        // "Au moins une": one branch is enough, and while none holds the student is missing the
        // whole alternative rather than any single leaf - so the row lists them all.
        $satisfied = \count($unmet) < \count($tree->leaves);

        return new AccessConditionVerdict($satisfied, $satisfied ? [] : $unmet);
    }

    /**
     * Several objects decided against one set of facts, which is the point of the split: a list of
     * thirty works costs the queries of one. Keys are the caller's own and come back untouched.
     *
     * @param array<TKey, ?AccessConditionTree> $trees
     *
     * @return array<TKey, AccessConditionVerdict>
     *
     * @template TKey of array-key
     */
    public function evaluateMany(array $trees, StudentAccessFacts $facts): array
    {
        return array_map(
            fn (?AccessConditionTree $tree): AccessConditionVerdict => $this->evaluate($tree, $facts),
            $trees,
        );
    }

    private function holds(AccessConditionLeaf $leaf, StudentAccessFacts $facts): bool
    {
        $id = $leaf->targetId;

        return match ($leaf->type) {
            // A quiz nobody has taken answers neither bound: reading a missing score as a low one
            // would open every remediation to the whole class before anybody composed.
            AccessConditionType::QuizScore => null !== $id && $this->scoreIsInRange($facts->quizBestPercents[$id] ?? null, $leaf),
            // Same reading for a grade nobody has yet: an ungraded, absent or excluded student is
            // not "under 10", they have no note at all, and neither bound holds for them.
            // A grade condition nobody can satisfy any more is **ignored, not enforced**: with the
            // carnet de notes switched off, "note ≥ 10" would never become true and the content
            // behind it would stay locked for ever, with nothing on screen to explain why
            // (design/validated/feature-access.md §8.4). The edit screen says so too.
            AccessConditionType::GradeValue => !$facts->gradesAreEnterable
                || (null !== $id && $this->gradeCompares($facts->gradeValues[$id] ?? null, $leaf)),
            AccessConditionType::AssignmentDone => null !== $id && isset($facts->doneAssignmentIds[$id]),
            AccessConditionType::AudioListened => null !== $id && ($facts->audioPercents[$id] ?? -1) >= $leaf->requiredPercent(),
            AccessConditionType::VideoWatched => null !== $id && ($facts->videoPercents[$id] ?? -1) >= $leaf->requiredPercent(),
            AccessConditionType::ResourceViewed => null !== $id && isset($facts->viewedResourceIds[$id]),
            // A séance with no slot has no date, so nothing opens - the cahier de texte's own policy
            // for "après la séance", kept identical here rather than re-decided.
            AccessConditionType::SeancePassed => null !== $id && $this->hasPassed($facts->seanceMoment($id, $leaf->moment), $facts->now),
            AccessConditionType::DateFrom => $this->hasPassed($leaf->at, $facts->now),
            AccessConditionType::Group => null !== $id && isset($facts->groupIds[$id]),
        };
    }

    private function scoreIsInRange(int|float|null $best, AccessConditionLeaf $leaf): bool
    {
        if (null === $best) {
            return false;
        }

        return (null === $leaf->minPercent || $best >= $leaf->minPercent)
            && (null === $leaf->maxPercent || $best <= $leaf->maxPercent);
    }

    /**
     * One bound, strictly. A range ("plus de 10 et moins de 15") is two leaves in "toutes les
     * conditions" mode, which is how the teacher writes it and how they read it back.
     */
    private function gradeCompares(?float $grade, AccessConditionLeaf $leaf): bool
    {
        if (null === $grade || null === $leaf->comparison || null === $leaf->value) {
            return false;
        }

        return $leaf->comparison->holds($grade, $leaf->value);
    }

    private function hasPassed(?\DateTimeImmutable $at, \DateTimeImmutable $now): bool
    {
        return null !== $at && $at <= $now;
    }
}
