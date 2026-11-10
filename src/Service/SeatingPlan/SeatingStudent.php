<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

/**
 * A student of the plan's class as the plan reads them: names, the option that the « mélanger les
 * options » preference looks at, and whether an accommodation asks for a seat at the front.
 */
final readonly class SeatingStudent
{
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public string $shortName,
        public ?string $option = null,
        public bool $seatInFront = false,
    ) {
    }

    /** @return array{id: int, firstName: string, lastName: string, shortName: string, option: ?string, front: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'shortName' => $this->shortName,
            'option' => $this->option,
            'front' => $this->seatInFront,
        ];
    }
}
