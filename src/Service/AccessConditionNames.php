<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AccessConditionType;

/**
 * What the objects an unmet condition points at are called - for this reader only.
 *
 * Absence is the whole point: an object missing from this map is one the reader is not entitled to
 * know about, and its sentence falls back on a generic ("une autre activité de la séquence"). A
 * greyed row must never be the thing that reveals somebody else's remediation exists, so the
 * decision of what to name is taken once, by AccessConditionNameResolver, and travels as this.
 */
final readonly class AccessConditionNames
{
    /** @param array<string, array<int, string>> $names leaf type value => object id => its name */
    public function __construct(private array $names = [])
    {
    }

    public function nameOf(AccessConditionType $type, ?int $id): ?string
    {
        return null === $id ? null : ($this->names[$type->value][$id] ?? null);
    }
}
