<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * « Longueur d'un mot »: how much a student may write in one submission.
 *
 * The three cases differ only in how many words are allowed - the thirty-character ceiling is the
 * same everywhere, and is what keeps a cloud a cloud rather than a wall of sentences. The handoff
 * names it only on the third case because that is the one where it is the *only* limit.
 */
enum WordCloudWordLength: string
{
    case OneWord = 'one_word';
    case UpToThreeWords = 'up_to_three_words';
    case FreeExpression = 'free_expression';

    public const int MAX_CHARACTERS = 30;

    /** null on « Expression libre »: the character ceiling is the whole rule there. */
    public function maxWords(): ?int
    {
        return match ($this) {
            self::OneWord => 1,
            self::UpToThreeWords => 3,
            self::FreeExpression => null,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::OneWord => 'wordCloudWordLengthOneWordLabel',
            self::UpToThreeWords => 'wordCloudWordLengthUpToThreeWordsLabel',
            self::FreeExpression => 'wordCloudWordLengthFreeExpressionLabel',
        };
    }
}
