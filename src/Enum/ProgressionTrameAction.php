<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What happens to one séquence of a shared progression trame - and it is shown **before the click**,
 * line by line (design/validated/content-sharing-between-teachers.md, mockup 9).
 *
 * None of these is an error. Three of them are the model's own constraints made visible rather than
 * discovered afterwards:
 *
 * - `Copied` is the ordinary path: the author's template is duplicated into the recipient's library,
 *   then instantiated for the class;
 * - `Reused` is `sequence_instance`'s `UNIQUE (source_template_id, program_id)`: somebody has already
 *   instantiated that very séquence for that class, and a second copy would be a duplicate for every
 *   teacher of it;
 * - `Detached` is a source séquence whose template has been deleted since. It cannot go through a
 *   library, so it is copied instance to instance - the class-level content survives and the
 *   recipient simply gets nothing new in their *library* for it;
 * - `Skipped` is `ProgressionSequenceAvailability`'s rule: a séquence is planned **once** for the
 *   whole class, so one already carried by another progression is left out - and **named**, never
 *   silently dropped.
 */
enum ProgressionTrameAction: string
{
    case Copied = 'copied';
    case Reused = 'reused';
    case Detached = 'detached';
    case Skipped = 'skipped';

    public function labelKey(): string
    {
        return match ($this) {
            self::Copied => 'progressionTrameActionCopiedLabel',
            self::Reused => 'progressionTrameActionReusedLabel',
            self::Detached => 'progressionTrameActionDetachedLabel',
            self::Skipped => 'progressionTrameActionSkippedLabel',
        };
    }

    public function isSkipped(): bool
    {
        return self::Skipped === $this;
    }
}
