<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What one column of a QuestionType::Apparier question holds - see
 * App\Entity\QuizQuestionDefinitionTrait's $matchingConfig.
 *
 * The choice is per *column*, not per item: a column mixing a photo and a sentence lays out badly
 * on a phone and reads as a mistake rather than a variation. Both columns are independent, so all
 * four combinations work - the common one being an image column of specimens/schemas against a text
 * column of names.
 */
enum MatchingSideKind: string
{
    case Texte = 'texte';
    case Image = 'image';

    public function labelKey(): string
    {
        return match ($this) {
            self::Texte => 'matchingSideKindTexteLabel',
            self::Image => 'matchingSideKindImageLabel',
        };
    }

    public function isImage(): bool
    {
        return self::Image === $this;
    }
}
