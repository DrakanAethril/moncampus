<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The life of one version of a question template (design/validated/serious-games.md §4, §9).
 *
 * A unit's **current** template is its highest `validated` version. A draft never serves a
 * student; a superseded version stays, because the questions already served by it are replayed
 * from it in the teachers' reading.
 */
enum SeriousGameTemplateStatus: string implements HasBadge
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Superseded = 'superseded';

    public function labelKey(): string
    {
        return match ($this) {
            self::Draft => 'seriousGameTemplateStatusDraft',
            self::Validated => 'seriousGameTemplateStatusValidated',
            self::Superseded => 'seriousGameTemplateStatusSuperseded',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Draft => BadgeTone::Gold,
            self::Validated => BadgeTone::Green,
            self::Superseded => BadgeTone::Gray,
        };
    }
}
