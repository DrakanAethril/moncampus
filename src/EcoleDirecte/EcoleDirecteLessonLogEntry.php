<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * One MonCampus séance with what its cahier de texte says, in primitives - what
 * App\EcoleDirecte\EcoleDirecteLessonLogPlanner matches against École Directe's slots.
 */
final readonly class EcoleDirecteLessonLogEntry
{
    public function __construct(
        public int $id,
        public string $date,
        public string $start,
        public string $label,
        public string $content,
        public string $workBefore,
        public string $workAfter,
    ) {
    }

    public function isEmpty(): bool
    {
        return '' === trim($this->content) && '' === trim($this->workBefore) && '' === trim($this->workAfter);
    }
}
