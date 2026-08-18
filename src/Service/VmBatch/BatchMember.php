<?php

declare(strict_types=1);

namespace App\Service\VmBatch;

/**
 * One person a batch is going to build a machine for, as plain values.
 *
 * `optionIds` and `modalityIds` travel with them because the targeting is done on the *student*,
 * not on the class: a Program offers options, and which students take which is a per-student fact
 * (App\Entity\ProgramStudentOption). Filtering the class by "the program has this option" would
 * deploy to everybody.
 */
final readonly class BatchMember
{
    /**
     * @param list<int> $optionIds
     * @param list<int> $modalityIds
     */
    public function __construct(
        public int $userId,
        public string $displayName,
        public string $login,
        public array $optionIds = [],
        public array $modalityIds = [],
    ) {
    }
}
