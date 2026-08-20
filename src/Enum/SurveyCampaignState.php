<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a SurveyCampaign stands right now - computed from its dates, never stored, exactly like
 * QuizInstance::isOpenNow() and for the same reason: a stored state desynchronises the moment a
 * date passes without anybody clicking anything.
 *
 * The method that answers it lives on the entity: SurveyCampaign::state().
 */
enum SurveyCampaignState: string
{
    /** Not launched yet: no frozen target, still editable. */
    case Draft = 'draft';

    /** Launched, but opens_at is still in the future. */
    case Scheduled = 'scheduled';

    case Open = 'open';

    case Closed = 'closed';

    public function labelKey(): string
    {
        return match ($this) {
            self::Draft => 'surveyCampaignStateDraftLabel',
            self::Scheduled => 'surveyCampaignStateScheduledLabel',
            self::Open => 'surveyCampaignStateOpenLabel',
            self::Closed => 'surveyCampaignStateClosedLabel',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'cm-badge--gray',
            self::Scheduled => 'cm-badge--blue',
            self::Open => 'cm-badge--green',
            self::Closed => 'cm-badge--gray',
        };
    }
}
