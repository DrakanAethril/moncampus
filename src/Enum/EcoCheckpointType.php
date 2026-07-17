<?php

namespace App\Enum;

// A parcours always gets exactly one Start and one Finish checkpoint, auto-created alongside it
// (see App\Service\EcoParcoursFactory) - Checkpoint is every numbered balise in between.
enum EcoCheckpointType: string
{
    case Start = 'start';
    case Checkpoint = 'checkpoint';
    case Finish = 'finish';

    public function labelKey(): string
    {
        return match ($this) {
            self::Start => 'ecoCheckpointTypeStartLabel',
            self::Checkpoint => 'ecoCheckpointTypeCheckpointLabel',
            self::Finish => 'ecoCheckpointTypeFinishLabel',
        };
    }

    // "D"/"n°"/"A" badge shown left of the checkpoint name (1e, 4b) - Checkpoint has no fixed
    // short letter, callers fall back to the checkpoint's own number for that case.
    public function shortLetter(): ?string
    {
        return match ($this) {
            self::Start => 'D',
            self::Finish => 'A',
            self::Checkpoint => null,
        };
    }
}
