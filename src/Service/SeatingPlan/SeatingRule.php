<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

use App\Enum\SeatingRuleType;

final readonly class SeatingRule
{
    /**
     * @param list<int> $studentIds
     */
    public function __construct(
        public string $id,
        public SeatingRuleType $type,
        public array $studentIds,
    ) {
    }

    /** @return array{id: string, type: string, students: list<int>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type->value, 'students' => $this->studentIds];
    }
}
