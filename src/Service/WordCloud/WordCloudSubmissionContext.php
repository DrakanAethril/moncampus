<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Enum\WordCloudWordLength;

/**
 * Everything WordCloudSubmissionPolicy needs to accept or refuse one word, and nothing else.
 *
 * Built by WordCloudSubmissionService from the cloud and the student's own history; kept as
 * primitives so that the rules can be exercised without a database - which is where they belong,
 * since every one of them is the difference between an activity that works and one a student can
 * flood.
 */
final readonly class WordCloudSubmissionContext
{
    public function __construct(
        public bool $open,
        public WordCloudWordLength $wordLength,
        /** null = « Illimités ». */
        public ?int $wordsPerStudent,
        /**
         * Every aggregation key this student has already submitted, **refused ones included**:
         * re-sending the word a teacher has just turned down is exactly what this prevents.
         *
         * @var list<string>
         */
        public array $alreadyProposedKeys = [],
        /**
         * How many of this student's words count against the quota. A refused word does not: the
         * teacher took it out, and making the student pay for it would leave them with fewer
         * chances than their neighbour for no fault of their own.
         */
        public int $countedTowardsQuota = 0,
    ) {
    }
}
