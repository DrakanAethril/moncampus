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
     * @param array<int, int>                 $quizBestPercents      best concluded score, keyed by quiz instance id
     * @param array<int, true>                $doneAssignmentIds     set of assignment ids handed in or declared done
     * @param array<int, int>                 $audioPercents         lowest listened percentage across the student's files, keyed by recording id
     * @param array<int, int>                 $videoPercents         same, keyed by video resource id
     * @param array<int, true>                $viewedResourceIds     set of library resource instance ids already opened
     * @param array<int, ?\DateTimeImmutable> $seanceStartDates      keyed by séance id; null means "no slot on the timetable yet"
     * @param array<int, ?\DateTimeImmutable> $seanceEndDates        keyed by séance id, same reading
     * @param array<int, true>                $groupIds              set of group ids the student belongs to
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
