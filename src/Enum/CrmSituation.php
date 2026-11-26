<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a dossier stands apart from its pieces. A new dossier starts « Candidature en attente »
 * (CrmDossier::$situation), the first of the list and the one a formation's board shows by
 * default. Only an admitted dossier (« Admis », stored `active`) counts in a formation's numbers;
 * the others keep their pieces and are listed under their own situation.
 */
enum CrmSituation: string implements HasBadge
{
    case Pending = 'pending';
    case Active = 'active';
    case Resigned = 'resigned';
    case Cancelled = 'cancelled';

    public function labelKey(): string
    {
        return match ($this) {
            self::Active => 'crmSituationActiveLabel',
            self::Pending => 'crmSituationPendingLabel',
            self::Resigned => 'crmSituationResignedLabel',
            self::Cancelled => 'crmSituationCancelledLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Active => BadgeTone::Green,
            self::Pending => BadgeTone::Teal,
            self::Resigned => BadgeTone::Red,
            self::Cancelled => BadgeTone::Gray,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Admis',
            self::Pending => 'Candidature en attente',
            self::Resigned => 'Démission',
            self::Cancelled => 'Annulation',
        };
    }

    public function isWaiting(): bool
    {
        return self::Pending === $this;
    }

    public function isClosed(): bool
    {
        return self::Resigned === $this || self::Cancelled === $this;
    }
}
