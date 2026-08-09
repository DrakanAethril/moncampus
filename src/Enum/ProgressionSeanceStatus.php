<?php

declare(strict_types=1);

namespace App\Enum;

// The status chip a séance shows on screen 2a. Never persisted: every case is derived live by
// App\Entity\ProgressionSeance::getStatus() from the séance's placements (see that method for the
// exact precedence). Storing it would mean re-syncing a column every time a créneau moves in the
// timetable, which is precisely the case ToReassociate exists to catch.
enum ProgressionSeanceStatus: string
{
    case Removed = 'removed';
    case NotPlaced = 'not_placed';
    case ToReassociate = 'to_reassociate';
    case Split = 'split';
    case PerGroup = 'per_group';
    case Associated = 'associated';
    case ToConfirm = 'to_confirm';

    public function labelKey(): string
    {
        return match ($this) {
            self::Removed => 'progressionSeanceStatusRemovedLabel',
            self::NotPlaced => 'progressionSeanceStatusNotPlacedLabel',
            self::ToReassociate => 'progressionSeanceStatusToReassociateLabel',
            self::Split => 'progressionSeanceStatusSplitLabel',
            self::PerGroup => 'progressionSeanceStatusPerGroupLabel',
            self::Associated => 'progressionSeanceStatusAssociatedLabel',
            self::ToConfirm => 'progressionSeanceStatusToConfirmLabel',
        };
    }

    // Design token suffix - drives .cm-prog-chip--{tone} (green / gold / red / plain).
    public function tone(): string
    {
        return match ($this) {
            self::Associated, self::PerGroup => 'ok',
            self::Split, self::ToConfirm => 'warn',
            self::NotPlaced, self::ToReassociate => 'danger',
            self::Removed => 'muted',
        };
    }
}
