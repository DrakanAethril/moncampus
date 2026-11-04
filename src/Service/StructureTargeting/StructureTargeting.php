<?php

declare(strict_types=1);

namespace App\Service\StructureTargeting;

use App\Entity\Program;
use App\Entity\StructureTargetable;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;

/**
 * Who a targeting reaches (design/validated/quiz-tournament.md §2 and §14) - the facts, read from the
 * database; the decision is StructureTargetRule's.
 *
 * Shared by everything a StructureTargetable opens to students: the quiz tournaments, and the
 * serious games once they are opened by targeting. Read at every gesture, never memoised beyond the
 * call: the rosters move during the year and the answer must move with them.
 */
class StructureTargeting
{
    public function __construct(
        private readonly ProgramRepository $programs,
        private readonly UserRepository $users,
    ) {
    }

    public function reaches(StructureTargetable $targetable, User $student): bool
    {
        $targets = StructureTargets::of($targetable);
        if ($targets->isEmpty()) {
            return false;
        }

        return StructureTargetRule::reaches($targets, $this->programs->findStructureFacts($student), $student->isTestUser());
    }

    /**
     * The person's Programs the targeting reaches, most recent first - the first is « la classe
     * affichée ».
     *
     * @return list<Program>
     */
    public function reachedPrograms(StructureTargetable $targetable, User $student): array
    {
        $targets = StructureTargets::of($targetable);
        if ($targets->isEmpty()) {
            return [];
        }

        $ids = array_map(
            static fn (StructureProgramFacts $program): int => $program->programId,
            StructureTargetRule::reachedPrograms($targets, $this->programs->findStructureFacts($student), $student->isTestUser()),
        );

        return $this->programsInOrder($ids);
    }

    /**
     * Every active Program the targeting reaches, both sides of the test zone, most recent first.
     *
     * @return list<int>
     */
    public function reachedProgramIds(StructureTargetable $targetable): array
    {
        $targets = StructureTargets::of($targetable);
        if ($targets->isEmpty()) {
            return [];
        }

        return array_map(
            static fn (StructureProgramFacts $program): int => $program->programId,
            StructureTargetRule::reachedPrograms($targets, $this->programs->findStructureFacts(), false),
        );
    }

    /**
     * Is this Program one the targeting reaches - active, and targeted itself or under a targeted
     * Track or Section? The test zone is the caller's: it depends on who is asking.
     */
    public function reachesProgram(StructureTargetable $targetable, Program $program): bool
    {
        return \in_array($program->getId(), $this->reachedProgramIds($targetable), true);
    }

    /**
     * Everybody the targeting reaches, keyed by user id, each with their most recent reached Program
     * - three queries whatever the size of the targeting (what the standings' « Pas encore joué » and
     * the serious games' « étudiants atteints » read). Roles are not checked here.
     *
     * @return array<int, ReachedStudent>
     */
    public function reachedStudents(StructureTargetable $targetable): array
    {
        $targets = StructureTargets::of($targetable);
        if ($targets->isEmpty()) {
            return [];
        }

        $reached = StructureTargetRule::reachedPrograms($targets, $this->programs->findStructureFacts(), false);
        if ([] === $reached) {
            return [];
        }

        $facts = [];
        foreach ($reached as $program) {
            $facts[$program->programId] = $program;
        }

        /** @var array<int, list<StructureProgramFacts>> $enrolmentsByUser */
        $enrolmentsByUser = [];
        foreach ($this->programs->findStudentEnrolments(array_keys($facts)) as $row) {
            $enrolmentsByUser[$row['userId']][] = $facts[$row['programId']];
        }
        if ([] === $enrolmentsByUser) {
            return [];
        }

        $users = [];
        foreach ($this->users->findBy(['id' => array_keys($enrolmentsByUser)]) as $user) {
            $users[(int) $user->getId()] = $user;
        }

        $programIdByUser = [];
        foreach ($enrolmentsByUser as $userId => $enrolments) {
            $user = $users[$userId] ?? null;
            if (null === $user) {
                continue;
            }

            // The test zone, per person: a test account is reached through test Programs only.
            $own = StructureTargetRule::reachedPrograms($targets, $enrolments, $user->isTestUser());
            if ([] !== $own) {
                $programIdByUser[$userId] = $own[0]->programId;
            }
        }

        $programs = [];
        foreach ($this->programsInOrder(array_values(array_unique($programIdByUser))) as $program) {
            $programs[(int) $program->getId()] = $program;
        }

        $result = [];
        foreach ($programIdByUser as $userId => $programId) {
            $result[$userId] = new ReachedStudent($users[$userId], $programs[$programId]);
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Program> in the order of $ids
     */
    private function programsInOrder(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $byId = [];
        foreach ($this->programs->findBy(['id' => $ids]) as $program) {
            $byId[(int) $program->getId()] = $program;
        }

        return array_values(array_filter(array_map(static fn (int $id): ?Program => $byId[$id] ?? null, $ids)));
    }
}
