<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A request to read one fiche from France compétences' open data.
 *
 * The click writes a Pending row and nothing else; `app:rncp:fetch` (every minute) takes it through
 * Running to Ready or Failed, and the administrator's confirmation makes it Applied. A web request
 * never downloads the 74 Mo export - production has eight workers, and one of them held for a
 * minute is an eighth of the site.
 */
enum RncpImportState: string implements HasBadge
{
    case Pending = 'pending';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';
    case Applied = 'applied';

    public function labelKey(): string
    {
        return match ($this) {
            self::Pending => 'rncpImportPendingLabel',
            self::Running => 'rncpImportRunningLabel',
            self::Ready => 'rncpImportReadyLabel',
            self::Failed => 'rncpImportFailedLabel',
            self::Applied => 'rncpImportAppliedLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Pending, self::Running => BadgeTone::Blue,
            self::Ready => BadgeTone::Gold,
            self::Failed => BadgeTone::Red,
            self::Applied => BadgeTone::Green,
        };
    }

    public function isWaiting(): bool
    {
        return self::Pending === $this || self::Running === $this;
    }
}
