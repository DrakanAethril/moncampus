<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * One MonCampus grade in primitives: who, and what École Directe should show for it - `14.5`, `abs`,
 * `ne`, `(12)` - or null when there is nothing to show (App\EcoleDirecte\EcoleDirecteGradePlanner::noteFor()).
 *
 * `$linkedId` and the linked names are the student's remembered École Directe identity
 * (App\Entity\EcoleDirecteStudentLink), when their names differ on the two sides.
 */
final readonly class EcoleDirecteGradeEntry
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $note,
        public int $studentId = 0,
        public ?int $linkedId = null,
        public string $linkedLastName = '',
        public string $linkedFirstName = '',
    ) {
    }

    public function label(): string
    {
        return trim($this->lastName.' '.$this->firstName);
    }

    public function linkedLabel(): ?string
    {
        return null === $this->linkedId ? null : trim($this->linkedLastName.' '.$this->linkedFirstName);
    }
}
