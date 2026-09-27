<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * One slot of a teacher's École Directe cahier de texte: the class, the subject, the time, and
 * whether a session content and a homework were written for it - as plain text, the screen only
 * reads it.
 */
final readonly class EcoleDirecteSlot
{
    public function __construct(
        public ?\DateTimeImmutable $date,
        public string $start,
        public string $end,
        public string $className,
        public string $subject,
        public string $room,
        public string $content,
        public string $homework,
        public bool $hasContent,
        public bool $hasHomework,
        public bool $test,
    ) {
    }
}
