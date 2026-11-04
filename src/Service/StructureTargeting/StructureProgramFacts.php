<?php

declare(strict_types=1);

namespace App\Service\StructureTargeting;

/**
 * What the targeting rule needs to know of one Program: where it sits in Section > Track > Cohort,
 * whether it is still active (Program::$inactiveDate null) and on which side of the test zone it is.
 */
final readonly class StructureProgramFacts
{
    public function __construct(
        public int $programId,
        public int $trackId,
        public int $sectionId,
        public bool $active,
        public bool $testProgram,
    ) {
    }
}
