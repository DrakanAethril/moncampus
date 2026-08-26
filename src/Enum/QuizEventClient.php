<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which client reported an event of the supervision journal.
 *
 * Kept for the human reading of the timeline and nothing else: App\Service\QuizSupervisionAssessor
 * does not know where an event came from, and must not - one rule, two clients. A phone that leaves
 * the app and a browser that leaves the tab are the same fact.
 */
enum QuizEventClient: string
{
    case Web = 'web';
    case Mobile = 'mobile';

    public function labelKey(): string
    {
        return match ($this) {
            self::Web => 'quizEventClientWebLabel',
            self::Mobile => 'quizEventClientMobileLabel',
        };
    }
}
