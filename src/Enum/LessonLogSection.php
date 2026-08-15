<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The three parts of a cahier de texte (design_handoff_cahier_de_texte 2a): what is asked for before
 * the séance, what was done in it, what is given afterwards. Each part carries its own visibility on
 * the student side, and an assignment as much as a document hangs off one of them.
 *
 * The three text fields already existed on LessonLog under these three names; this enum names them
 * for what must now hang off them.
 */
enum LessonLogSection: string
{
    case Before = 'before';
    case During = 'during';
    case After = 'after';

    public function labelKey(): string
    {
        return match ($this) {
            self::Before => 'lessonLogSectionBeforeLabel',
            self::During => 'lessonLogSectionDuringLabel',
            self::After => 'lessonLogSectionAfterLabel',
        };
    }

    // Numbered chip of the section header: amber, blue, grey (mockup 2a).
    public function badgeClass(): string
    {
        return match ($this) {
            self::Before => 'cm-step--gold',
            self::During => 'cm-step--blue',
            self::After => 'cm-step--gray',
        };
    }

    public function number(): int
    {
        return match ($this) {
            self::Before => 1,
            self::During => 2,
            self::After => 3,
        };
    }

    /**
     * An assignment is given before or after the séance; the « during » part is the account of what
     * was done, it only carries text and documents.
     *
     * @return list<self>
     */
    public static function acceptingWork(): array
    {
        return [self::Before, self::After];
    }
}
