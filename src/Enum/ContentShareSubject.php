<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which of the five things a share carries - see design/validated/content-sharing-between-teachers.md.
 *
 * Not a stored column: App\Entity\ContentShare holds five nullable foreign keys and exactly one is
 * filled, which is what makes the cascade real. This enum is how the screens name that one - the
 * pictogram on a row, the label of a type filter, the branch a duplication takes.
 */
enum ContentShareSubject: string implements HasBadge
{
    case Sequence = 'sequence';
    case Seance = 'seance';
    case Quiz = 'quiz';
    case File = 'file';
    case Progression = 'progression';

    /**
     * The colour a row's type badge carries - the five of the mockups, in the palette app.css
     * already declares (`cm-badge--*`), so no new colour enters the design system for this.
     */
    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Sequence => BadgeTone::Blue,
            self::Seance => BadgeTone::Teal,
            self::Quiz => BadgeTone::Gold,
            self::File => BadgeTone::Gray,
            self::Progression => BadgeTone::Green,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Sequence => 'contentShareSubjectSequenceLabel',
            self::Seance => 'contentShareSubjectSeanceLabel',
            self::Quiz => 'contentShareSubjectQuizLabel',
            self::File => 'contentShareSubjectFileLabel',
            self::Progression => 'contentShareSubjectProgressionLabel',
        };
    }
}
