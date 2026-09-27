<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * One MonCampus grade in primitives: who, and what École Directe should show for it - `14.5`, `abs`,
 * `ne`, `(12)` - or null when there is nothing to show (App\EcoleDirecte\EcoleDirecteGradePlanner::noteFor()).
 */
final readonly class EcoleDirecteGradeEntry
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $note,
    ) {
    }

    public function label(): string
    {
        return trim($this->lastName.' '.$this->firstName);
    }
}
