<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How much remote work an offer says it allows.
 *
 * **`NonPrecise` is not `Aucun`**, and the whole feature depends on not confusing them: most
 * adverts simply do not raise the subject, and reading their silence as "no remote work" would
 * falsify the entire column. The screen draws no tag at all for `NonPrecise` - see
 * design/validated/jobboard.md §6.1 - and the ingestion lets a precise value replace it but never
 * the other way round.
 */
enum JobboardRemote: string
{
    case Aucun = 'aucun';
    case Partiel = 'partiel';
    case Total = 'total';
    case NonPrecise = 'non_precise';

    public function labelKey(): string
    {
        return match ($this) {
            self::Aucun => 'jobboardRemoteAucunLabel',
            self::Partiel => 'jobboardRemotePartielLabel',
            self::Total => 'jobboardRemoteTotalLabel',
            self::NonPrecise => 'jobboardRemoteNonPreciseLabel',
        };
    }

    /** Does this value say something? Only then does the list draw a tag. */
    public function isStated(): bool
    {
        return self::NonPrecise !== $this;
    }

    /**
     * Accepts the legacy file's `"non précisé"` alongside the contract's `non_precise`: same value,
     * written the way it was displayed at the time.
     */
    public static function tryFromLoose(string $value): ?self
    {
        $normalised = strtolower(trim($value));
        $normalised = str_replace(['é', 'è', 'ê'], 'e', $normalised);
        $normalised = str_replace([' ', '-'], '_', $normalised);

        return self::tryFrom($normalised);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
