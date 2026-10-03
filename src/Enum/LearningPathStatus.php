<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a learning path stands (design/validated/cours-en-ligne.md, §10). A path is born a draft
 * and is followed once published - by people holding an account only: nothing of a path is read
 * without one, whatever its status.
 *
 * The value is stored, so renaming a case means migrating rows.
 */
enum LearningPathStatus: string implements HasBadge
{
    case Draft = 'draft';
    case Published = 'published';

    public function labelKey(): string
    {
        return match ($this) {
            self::Draft => 'learningPathStatusDraftLabel',
            self::Published => 'learningPathStatusPublishedLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Draft => BadgeTone::Gray,
            self::Published => BadgeTone::Green,
        };
    }
}
