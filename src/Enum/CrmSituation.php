<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a dossier stands apart from its pieces. Only an active dossier counts in a formation's
 * numbers; the others keep their pieces and are listed in their own groups.
 */
enum CrmSituation: string implements HasBadge
{
    case Active = 'active';
    case Pending = 'pending';
    case Waitlist = 'waitlist';
    case Resigned = 'resigned';
    case Cancelled = 'cancelled';

    public function labelKey(): string
    {
        return match ($this) {
            self::Active => 'crmSituationActiveLabel',
            self::Pending => 'crmSituationPendingLabel',
            self::Waitlist => 'crmSituationWaitlistLabel',
            self::Resigned => 'crmSituationResignedLabel',
            self::Cancelled => 'crmSituationCancelledLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Active => BadgeTone::Green,
            self::Pending => BadgeTone::Teal,
            self::Waitlist => BadgeTone::Gold,
            self::Resigned => BadgeTone::Red,
            self::Cancelled => BadgeTone::Gray,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'En cours',
            self::Pending => 'Candidature en attente',
            self::Waitlist => 'Liste d\'attente',
            self::Resigned => 'Démission',
            self::Cancelled => 'Annulation',
        };
    }

    public function isWaiting(): bool
    {
        return self::Pending === $this || self::Waitlist === $this;
    }

    public function isClosed(): bool
    {
        return self::Resigned === $this || self::Cancelled === $this;
    }
}
