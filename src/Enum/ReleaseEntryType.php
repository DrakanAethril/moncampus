<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What one line of a release note is: the five buckets the changelog page sorts on.
 *
 * The distinction that carries weight is Internal versus the rest. Everything else describes
 * something the staff can see and use; Internal describes work on the code itself - static
 * analysis, indexes, refactors - which belongs in the record (the repository is public and under
 * the AGPL) but not in the first thing a teacher reads. The page folds it away for that reason.
 */
enum ReleaseEntryType: string
{
    case Feature = 'nouveaute';
    case Change = 'modification';
    case Fix = 'fix';
    case Internal = 'interne';
    case Other = 'autre';

    public function labelKey(): string
    {
        return match ($this) {
            self::Feature => 'changelogTypeFeatureLabel',
            self::Change => 'changelogTypeChangeLabel',
            self::Fix => 'changelogTypeFixLabel',
            self::Internal => 'changelogTypeInternalLabel',
            self::Other => 'changelogTypeOtherLabel',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Feature => 'cm-badge--green',
            self::Change => 'cm-badge--blue',
            self::Fix => 'cm-badge--gold',
            self::Internal => 'cm-badge--gray',
            self::Other => 'cm-badge--teal',
        };
    }

    /** Whether this line is about the product rather than about the code that carries it. */
    public function isProductFacing(): bool
    {
        return self::Internal !== $this;
    }

    /** The order the page lists them in: what was added, then what changed, then what was repaired. */
    public function weight(): int
    {
        return match ($this) {
            self::Feature => 0,
            self::Change => 1,
            self::Fix => 2,
            self::Other => 3,
            self::Internal => 4,
        };
    }
}
