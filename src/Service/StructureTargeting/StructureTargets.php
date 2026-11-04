<?php

declare(strict_types=1);

namespace App\Service\StructureTargeting;

use App\Entity\Program;
use App\Entity\Section;
use App\Entity\StructureTargetable;
use App\Entity\Track;

/**
 * The ids a targeting names, at each of its three levels - what StructureTargetRule reads instead
 * of the entities, so the rule stays a function of primitives.
 */
final readonly class StructureTargets
{
    /**
     * @param list<int> $sectionIds
     * @param list<int> $trackIds
     * @param list<int> $programIds
     */
    public function __construct(
        public array $sectionIds,
        public array $trackIds,
        public array $programIds,
    ) {
    }

    public static function of(StructureTargetable $targetable): self
    {
        return new self(
            array_values(array_map(static fn (Section $section): int => (int) $section->getId(), $targetable->getTargetSections()->toArray())),
            array_values(array_map(static fn (Track $track): int => (int) $track->getId(), $targetable->getTargetTracks()->toArray())),
            array_values(array_map(static fn (Program $program): int => (int) $program->getId(), $targetable->getTargetPrograms()->toArray())),
        );
    }

    /** A targeting that names nothing reaches nobody. */
    public function isEmpty(): bool
    {
        return [] === $this->sectionIds && [] === $this->trackIds && [] === $this->programIds;
    }
}
