<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

/**
 * A quiz that cannot become a course's test (App\Service\OnlineCourse\OnlineCourseWriter::linkQuiz()).
 * The message is a translation key, `$parameters` what it interpolates: it is shown on the author's
 * screen, and read by the Claude connector's model.
 */
final class OnlineCourseQuizRefused extends \RuntimeException
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(string $messageKey, public readonly array $parameters = [])
    {
        parent::__construct($messageKey);
    }
}
