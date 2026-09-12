<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The countries the veille covers. Stored with their French spelling because that spelling *is* the
 * value the contract guarantees (design/jobboard/crawler/API-veille-contrat-de-donnees.md §2), and
 * rewriting it into a slug would make every payload need a translation table for nothing.
 *
 * `departement` is only legal for France - the ingestion refuses it anywhere else.
 */
enum JobboardCountry: string
{
    case France = 'France';
    case Belgique = 'Belgique';
    case Suisse = 'Suisse';
    case Luxembourg = 'Luxembourg';
    case Canada = 'Canada';

    public function label(): string
    {
        return $this->value;
    }

    public static function tryFromLoose(string $value): ?self
    {
        foreach (self::cases() as $case) {
            if (0 === strcasecmp(trim($value), $case->value)) {
                return $case;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
