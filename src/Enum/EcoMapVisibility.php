<?php

namespace App\Enum;

// What the runner's in-race map (mobile screen 3f) shows - the runner's own position is never
// shown regardless of this setting, see e-CO.dc.html's "Votre position n'apparaît pas sur la
// carte" note.
enum EcoMapVisibility: string
{
    case AllCheckpoints = 'all_checkpoints';
    case ValidatedPlusNext = 'validated_plus_next';
    case NextOnly = 'next_only';
    case None = 'none';

    public function labelKey(): string
    {
        return match ($this) {
            self::AllCheckpoints => 'ecoMapVisibilityAllCheckpointsLabel',
            self::ValidatedPlusNext => 'ecoMapVisibilityValidatedPlusNextLabel',
            self::NextOnly => 'ecoMapVisibilityNextOnlyLabel',
            self::None => 'ecoMapVisibilityNoneLabel',
        };
    }
}
