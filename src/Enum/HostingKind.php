<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What one of our students did at a company: a **stage** or an **alternance**.
 *
 * The two are never added together anywhere (design/validated/vivier-entreprises.md, D2): a
 * company that took two trainees and one apprentice reads « 2 stages · 1 alternance », and a
 * screen that has no room for both says which one it counts. A company may well have done both.
 */
enum HostingKind: string implements HasBadge
{
    case Stage = 'stage';
    case Alternance = 'alternance';

    public function labelKey(): string
    {
        return match ($this) {
            self::Stage => 'hostingKindStageLabel',
            self::Alternance => 'hostingKindAlternanceLabel',
        };
    }

    /** The plural used as a section title on the fiche (« Stages », « Alternances »). */
    public function pluralLabelKey(): string
    {
        return match ($this) {
            self::Stage => 'hostingKindStagePluralLabel',
            self::Alternance => 'hostingKindAlternancePluralLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Stage => BadgeTone::Blue,
            self::Alternance => BadgeTone::Green,
        };
    }
}
