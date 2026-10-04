<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The three parts of the ECF that carry their own visas: an activity-type's « Fiche de résultats »,
 * its « Évaluations complémentaires » page, and the closing synthesis. Evaluation rows only ever
 * belong to the first two.
 */
enum EcfPart: string
{
    case Main = 'main';
    case Complementary = 'complementary';
    case Synthesis = 'synthesis';

    /** How many evaluation rows the printed page holds at most - null when it grows with the content. */
    public function maxRows(): ?int
    {
        return match ($this) {
            self::Complementary => 4,
            self::Main, self::Synthesis => null,
        };
    }
}
