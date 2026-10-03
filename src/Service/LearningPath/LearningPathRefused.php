<?php

declare(strict_types=1);

namespace App\Service\LearningPath;

/**
 * Something a learning path cannot take or do. The message is a translation key, `$parameters`
 * what it interpolates: shown on the author's screen, read by the Claude connector's model.
 */
final class LearningPathRefused extends \RuntimeException
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(string $messageKey, public readonly array $parameters = [])
    {
        parent::__construct($messageKey);
    }
}
