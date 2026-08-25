<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AccessConditionMoment;

/**
 * What one student has actually done, as primitives - the other half of the access-condition
 * decision, and the only half that costs queries. Loaded once for a whole screen by
 * AccessConditionFactsLoader, then read by AccessConditionEvaluator without touching anything else.
 *
 * The split is what keeps the rule testable and the screens cheap: thirty locked rows are decided
 * against one of these, not against thirty round trips.
 */
final readonly class StudentAccessFacts
{
    /**
     * @param array<int, float>               $quizBestPercents      best concluded score, keyed by quiz instance id - a float, since a score is
     *                                                               a ratio of correct answers and not a whole percentage
     * @param array<int, true>                $doneAssignmentIds     set of assignment ids handed in or declared done
     * @param array<int, int>                 $audioPercents         lowest listened percentage across the student's files, keyed by recording id
     * @param array<int, int>                 $videoPercents         same, keyed by video resource id
     * @param array<int, true>                $viewedResourceIds     set of library resource instance ids already opened
     * @param array<int, ?\DateTimeImmutable> $seanceStartDates      keyed by séance id; null means "no slot on the timetable yet"
     * @param array<int, ?\DateTimeImmutable> $seanceEndDates        keyed by séance id, same reading
     * @param array<int, true>                $groupIds              set of group ids the student belongs to
     * @param array<int, float>               $gradeValues           the grade actually awarded, keyed by evaluation id, in that
     *                                                               evaluation's own barème - a missing key is a student with no
     *                                                               grade at all (never graded, absent, excluded), not a zero
     * @param bool                            $gradesAreEnterable    whether anybody can still enter a grade at all - see
     *                                                               AccessConditionEvaluator::holds(). False makes every
     *                                                               `grade_value` leaf hold rather than block: a condition
     *                                                               nobody can satisfy any more must not lock content for ever
     *                                                               (design/validated/feature-access.md §8.4)
     */
    public function __construct(
        public \DateTimeImmutable $now,
        public array $quizBestPercents = [],
        public array $doneAssignmentIds = [],
        public array $audioPercents = [],
        public array $videoPercents = [],
        public array $viewedResourceIds = [],
        public array $seanceStartDates = [],
        public array $seanceEndDates = [],
        public array $groupIds = [],
        public array $gradeValues = [],
        public bool $gradesAreEnterable = true,
    ) {
    }

    /**
     * When the séance sits on the timetable, or null while it has no slot - the one fact that is
     * identical for every student of the program.
     */
    public function seanceMoment(int $seanceId, AccessConditionMoment $moment): ?\DateTimeImmutable
    {
        return AccessConditionMoment::Start === $moment
            ? ($this->seanceStartDates[$seanceId] ?? null)
            : ($this->seanceEndDates[$seanceId] ?? null);
    }
}
