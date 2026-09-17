<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a band of a detailed barème does to the grade.
 *
 * Standard is the ordinary case - a teacher-named part whose questions are what the evaluation is
 * marked out of. The two others are the exception the whole enum exists for: their points are
 * awarded on top of that reference, so they move the grade without moving what it is out of. An
 * evaluation summing 20 standard points, 2 of possible bonus and 4 of possible malus is still an
 * evaluation « sur 20 » - which is precisely how a 22/20 comes about.
 *
 * There is at most one Bonus and one Malus section per Evaluation, always after the standard ones,
 * and neither carries a name: App\Service\EvaluationRubricBuilder writes them blank and the screens
 * read labelKey() instead, so the wording follows the reader's language rather than the language of
 * whoever built the barème.
 */
enum RubricSectionKind: string
{
    case Standard = 'standard';
    case Bonus = 'bonus';
    case Malus = 'malus';

    /**
     * Whether this band's points count towards the sum the evaluation is marked out of. The whole
     * feature is this one question answered `false` twice.
     */
    public function countsTowardReference(): bool
    {
        return self::Standard === $this;
    }

    // +1 or -1: the sign the awarded points carry into the total. Points are always entered as a
    // magnitude (a 4-point malus is entered as "4", never "-4"), so this is where the minus lives.
    public function sign(): int
    {
        return self::Malus === $this ? -1 : 1;
    }

    public function isStandard(): bool
    {
        return self::Standard === $this;
    }

    // Null for Standard, whose name is the teacher's own.
    public function labelKey(): ?string
    {
        return match ($this) {
            self::Standard => null,
            self::Bonus => 'rubricSectionBonusLabel',
            self::Malus => 'rubricSectionMalusLabel',
        };
    }

    // The « + » / « − » the screens put in front of a question's points, so a box being filled says
    // on its own which way it moves the grade.
    public function signPrefix(): string
    {
        return match ($this) {
            self::Standard => '',
            self::Bonus => '+',
            self::Malus => '−',
        };
    }
}
