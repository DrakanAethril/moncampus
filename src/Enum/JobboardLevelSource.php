<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where the `niveau` of an offer comes from: read on the advert, or deduced by the agent from its
 * title.
 *
 * The field exists so that uncertainty stays visible instead of being drowned - the screen prints a
 * golden `≈` after an estimated level. It is also the field that carries the asymmetry of the
 * ingestion: a level read on the advert replaces an estimated one, never the other way round
 * (design/validated/jobboard.md §4.6).
 */
enum JobboardLevelSource: string
{
    case Annonce = 'annonce';
    case Estime = 'estime';

    public function labelKey(): string
    {
        return match ($this) {
            self::Annonce => 'jobboardLevelSourceAnnonceLabel',
            self::Estime => 'jobboardLevelSourceEstimeLabel',
        };
    }

    /** Is this reading better than the one already stored? Only `annonce` over `estime` is. */
    public function improves(self $stored): bool
    {
        return self::Annonce === $this && self::Estime === $stored;
    }

    /** Accepts the legacy file's accented `"estimé"`. */
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
