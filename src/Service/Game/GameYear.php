<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Program;

/**
 * The school year a formation is ranked over, as a pair of dates and a label.
 *
 * Read from App\Entity\SchoolYear when the formation has one, and otherwise from the calendar: a
 * school year runs September to August, so « the year » means something even for a formation nobody
 * has attached to one. The yearly ranking must never be the screen that refuses to open.
 */
final readonly class GameYear
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $label,
    ) {
    }

    public static function forProgram(Program $program, ?\DateTimeImmutable $now = null): self
    {
        $now ??= new \DateTimeImmutable();
        $schoolYear = $program->getSchoolYear();

        $from = $schoolYear?->getStartDate();
        $to = $schoolYear?->getEndDate();

        if (null === $from || null === $to) {
            // September to August, which is what a school year is when nobody has said otherwise.
            $startYear = (int) $now->format('n') >= 9 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;
            $from = new \DateTimeImmutable(\sprintf('%d-09-01 00:00:00', $startYear));
            $to = new \DateTimeImmutable(\sprintf('%d-08-31 23:59:59', $startYear + 1));
        }

        return new self($from, $to, \sprintf('%s - %s', $from->format('Y'), $to->format('Y')));
    }
}
