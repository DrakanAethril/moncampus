<?php

declare(strict_types=1);

namespace App\Service\SeriousGame;

use App\Entity\Program;
use App\Entity\SeriousGame;
use App\Repository\ProgramRepository;
use App\Service\StructureTargeting\ReachedStudent;
use App\Service\StructureTargeting\StructureTargeting;

/**
 * Whom a serious game's targeting reaches today (design/validated/quiz-tournament.md §14, « la liste
 * des étudiants qu'il atteint à date ») - what Paramètres › Serious games shows beside the field.
 *
 * Read from StructureTargeting::reachedStudents(), the very reading the access rule makes, and kept
 * to the accounts holding ROLE_STUDENT. Each student is counted once, under their most recent reached
 * formation (« la classe affichée »), so the formations' counts add up to the total; a reached
 * formation whose students are all counted elsewhere still shows, with 0. Whether the module and the
 * game are lit is the screen's to say: this is the targeting alone.
 *
 * @phpstan-type ReachRow array{program: Program, students: int}
 * @phpstan-type Reach array{total: int, programs: list<ReachRow>}
 */
class SeriousGameStudentReach
{
    public function __construct(
        private readonly StructureTargeting $targeting,
        private readonly ProgramRepository $programs,
    ) {
    }

    /** @return Reach the formations in name order */
    public function of(SeriousGame $game): array
    {
        $students = $this->students($game);

        $counts = array_fill_keys($this->targeting->reachedProgramIds($game), 0);
        foreach ($students as $student) {
            $id = (int) $student->program->getId();
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        if ([] === $counts) {
            return ['total' => 0, 'programs' => []];
        }

        $rows = [];
        foreach ($this->programs->findBy(['id' => array_keys($counts)]) as $program) {
            $rows[] = ['program' => $program, 'students' => $counts[(int) $program->getId()]];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['program']->getShortName(), $a['program']->getId()] <=> [$b['program']->getShortName(), $b['program']->getId()]);

        return ['total' => \count($students), 'programs' => $rows];
    }

    public function count(SeriousGame $game): int
    {
        return \count($this->students($game));
    }

    /** @return list<ReachedStudent> */
    private function students(SeriousGame $game): array
    {
        return array_values(array_filter(
            $this->targeting->reachedStudents($game),
            static fn (ReachedStudent $student): bool => \in_array('ROLE_STUDENT', $student->user->getRoles(), true),
        ));
    }
}
