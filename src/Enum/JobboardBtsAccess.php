<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The agent's judgement on whether a BTS graduate can hold this job.
 *
 * **It is never displayed.** Not as a tag, not as a line of the detail panel: it is an
 * administrator's filtering criterion and nothing else (design/validated/jobboard.md §5.3). Telling
 * a student that an offer is "accessible en sortie de BTS" would turn one agent's guess into the
 * establishment's advice.
 */
enum JobboardBtsAccess: string
{
    case Accessible = 'accessible';
    case Poursuite = 'poursuite';
    case Superieur = 'superieur';

    public function labelKey(): string
    {
        return match ($this) {
            self::Accessible => 'jobboardBtsAccessAccessibleLabel',
            self::Poursuite => 'jobboardBtsAccessPoursuiteLabel',
            self::Superieur => 'jobboardBtsAccessSuperieurLabel',
        };
    }

    public static function tryFromLoose(string $value): ?self
    {
        $normalised = strtolower(trim($value));
        $normalised = str_replace(['é', 'è', 'ê'], 'e', $normalised);

        return self::tryFrom($normalised);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
