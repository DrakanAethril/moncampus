<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a Zone/Légende question shows the student to click into. Not a column - a key of the
 * question's zone config JSON (App\Entity\QuizQuestionDefinitionTrait), same status as BlankMode
 * inside the blanks config.
 *
 * - Texte: prose with inline zones (click the verb, the anachronism, the figure de style).
 * - Code: the same, rendered monospace with line numbers (click the closing tag, the selector).
 * - Image: the question's image with drawn rectangle zones (click the organ, label the diagram).
 */
enum ZoneSupportKind: string
{
    case Texte = 'texte';
    case Code = 'code';
    case Image = 'image';

    public function labelKey(): string
    {
        return match ($this) {
            self::Texte => 'zoneSupportKindTexteLabel',
            self::Code => 'zoneSupportKindCodeLabel',
            self::Image => 'zoneSupportKindImageLabel',
        };
    }
}
