<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

/**
 * A course that cannot go online yet. `$reasons` are translation keys, one per thing missing, so
 * the screen - and the Claude connector's model - can name them all at once.
 */
final class OnlineCoursePublicationRefused extends \RuntimeException
{
    /**
     * @param non-empty-list<string> $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct($reasons[0]);
    }
}
