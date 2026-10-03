<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a step of a learning path is, for one person, today - the answer of
 * App\Service\LearningPath\LearningPathRule. Never stored: it is read at every display, from what
 * the person opened and scored against the path as it stands.
 */
enum LearningPathStepState: string
{
    /** Its course is offline or its quiz is gone: skipped, and it closes nothing. */
    case Unavailable = 'unavailable';
    /** A validation quiz before it has not been passed. */
    case Locked = 'locked';
    /** Reachable, and not done yet. */
    case Open = 'open';
    /** A course that was opened, a quiz that was validated. */
    case Done = 'done';

    public function isReachable(): bool
    {
        return self::Open === $this || self::Done === $this;
    }
}
