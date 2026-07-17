<?php

namespace App\Enum;

// Not a persisted column - always computed live from an EcoParcours' checkpoints
// (EcoParcours::getStatus()) since a teacher's own parcours list is small enough that recomputing
// on every read is simpler than keeping a stored status in sync with checkpoint location changes.
enum EcoParcoursStatus: string
{
    case Draft = 'draft';
    case ToLocate = 'to_locate';
    case Ready = 'ready';

    public function labelKey(): string
    {
        return match ($this) {
            self::Draft => 'ecoParcoursStatusDraftLabel',
            self::ToLocate => 'ecoParcoursStatusToLocateLabel',
            self::Ready => 'ecoParcoursStatusReadyLabel',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-secondary-lt',
            self::ToLocate => 'bg-yellow-lt',
            self::Ready => 'bg-green-lt',
        };
    }
}
