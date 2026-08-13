<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizInstance;

/**
 * The quizzes a student may see, and why each of them is open or held.
 *
 * A pair rather than two calls, because the two always travel together: the list is already the
 * result of applying the verdicts (invisible ones are gone), and the caller still needs those same
 * verdicts to grey the rows it keeps. Asking the gate twice would be a second set of queries and a
 * second chance to disagree with the first.
 */
final class StudentQuizReadableInstances
{
    /** @param list<QuizInstance> $instances */
    public function __construct(
        public readonly array $instances,
        public readonly AccessConditionVerdictMap $verdicts,
    ) {
    }
}
