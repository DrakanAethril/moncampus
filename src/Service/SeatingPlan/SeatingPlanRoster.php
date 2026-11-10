<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramStudentOptionRepository;
use App\Service\Accommodation\AccommodationResolver;

/**
 * The students a plan places: the class today, in surname order, each with the option the
 * « mélanger les options » preference reads (their first one) and whether an accommodation asks
 * for a seat at the front.
 */
final class SeatingPlanRoster
{
    public function __construct(
        private readonly ProgramStudentOptionRepository $studentOptions,
        private readonly AccommodationResolver $accommodations,
    ) {
    }

    /** @return array<int, SeatingStudent> keyed by student id */
    public function forProgram(Program $program): array
    {
        $options = $this->studentOptions->findOptionsByStudentForProgram($program);

        $students = array_values(array_filter($program->getStudents()->toArray(), static fn (User $student): bool => null !== $student->getId()));
        usort($students, static fn (User $a, User $b): int => [mb_strtolower($a->getLastname() ?? ''), mb_strtolower($a->getFirstname() ?? '')] <=> [mb_strtolower($b->getLastname() ?? ''), mb_strtolower($b->getFirstname() ?? '')]);

        $roster = [];
        foreach ($students as $student) {
            $id = (int) $student->getId();
            $first = $student->getFirstname() ?? '';
            $last = $student->getLastname() ?? '';
            if ('' === $first && '' === $last) {
                $first = $student->getDisplayName() ?? $student->getUsername();
            }
            $option = ($options[$id] ?? [])[0] ?? null;
            $roster[$id] = new SeatingStudent(
                $id,
                $first,
                $last,
                $student->getShortDisplayName() ?? trim($first.' '.('' === $last ? '' : mb_substr($last, 0, 1).'.')),
                $option?->getShortName() ?: $option?->getName(),
                $this->accommodations->forUser($student)->seatInFront,
            );
        }

        return $roster;
    }
}
