<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The life of a réalisation or of an E6 fiche (design/validated/portfolio.md §5).
 *
 * Brouillon → À valider → Validée, or → À reprendre → À valider. A validated piece the student
 * changes goes back to À valider: a validation covers one revision, never the text written after it.
 */
enum PortfolioState: string implements HasBadge
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ToRework = 'to_rework';
    case Validated = 'validated';

    public function labelKey(): string
    {
        return match ($this) {
            self::Draft => 'portfolioStateDraftLabel',
            self::Submitted => 'portfolioStateSubmittedLabel',
            self::ToRework => 'portfolioStateToReworkLabel',
            self::Validated => 'portfolioStateValidatedLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Draft => BadgeTone::Gray,
            self::Submitted => BadgeTone::Gold,
            self::ToRework => BadgeTone::Red,
            self::Validated => BadgeTone::Green,
        };
    }

    /** May the student still change it without the change being a new revision to validate? */
    public function isAwaitingDecision(): bool
    {
        return self::Submitted === $this;
    }
}
