<?php

declare(strict_types=1);

namespace App\Service\Kahoot;

/**
 * Something the import of a Kahoot report wants the teacher to know about one question: a margin
 * guessed from the game, a question type Formatool does not play, a question whose right answer the
 * report does not let us find. Worded by App\Service\Kahoot\KahootReportReader::describe().
 */
final readonly class KahootNote
{
    public const string TOLERANCE_GUESSED = 'tolerance_guessed';
    public const string TOLERANCE_UNKNOWN = 'tolerance_unknown';
    public const string SKIPPED_TYPE = 'skipped_type';
    public const string NO_CORRECT_ANSWER = 'no_correct_answer';
    public const string NO_SLIDER_VALUE = 'no_slider_value';

    /** @param array<string, string> $parameters */
    public function __construct(
        public string $kind,
        public int $questionNumber,
        public array $parameters = [],
    ) {
    }

    /** Whether the question this note is about was left out of the quiz. */
    public function dropsQuestion(): bool
    {
        return \in_array($this->kind, [self::SKIPPED_TYPE, self::NO_CORRECT_ANSWER, self::NO_SLIDER_VALUE], true);
    }
}
