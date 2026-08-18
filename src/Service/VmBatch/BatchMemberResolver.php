<?php

declare(strict_types=1);

namespace App\Service\VmBatch;

use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramStudentModalityRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Service\Guest\UnixLogin;

/**
 * Reads a class out of the database and flattens it into the plain values
 * App\Service\VmBatch\VmBatchPlanner works on.
 *
 * The reason for the split is the targeting. A Program *offers* options and modalities; which
 * students actually take which is a **per-student** fact, held by ProgramStudentOption and
 * ProgramStudentModality. Reading the program's own collections would answer "SIO2 offers SISR" and
 * match all twenty-four students, which is precisely the mistake that turns "eight machines for the
 * SISR group" into a full class. So each member carries their own option and modality ids, and the
 * filtering happens on those.
 */
class BatchMemberResolver
{
    public function __construct(
        private readonly ProgramStudentOptionRepository $studentOptions,
        private readonly ProgramStudentModalityRepository $studentModalities,
        private readonly UnixLogin $unixLogin,
    ) {
    }

    /** @return list<BatchMember> */
    public function forProgram(Program $program): array
    {
        $members = [];

        foreach ($program->getStudents() as $student) {
            $members[] = new BatchMember(
                $student->getId() ?? 0,
                $student->getDisplayName() ?? $student->getUsername(),
                $this->loginFor($student),
                $this->optionIdsFor($program, $student),
                $this->modalityIdsFor($program, $student),
            );
        }

        // Sorted by the name people read, so the machines of a batch come out in the order of the
        // list somebody is holding.
        usort($members, static fn (BatchMember $a, BatchMember $b): int => strnatcasecmp($a->displayName, $b->displayName));

        return $members;
    }

    /**
     * The login a student gets on their machine.
     *
     * Computed from the name rather than from the LDAP username on purpose: the username is what
     * the school's directory decided, and it is sometimes a number. `marie-dupont` is what a
     * student can be told over the noise of a classroom.
     */
    private function loginFor(User $student): string
    {
        $firstname = $student->getFirstname();
        $lastname = $student->getLastname();

        if (null !== $firstname && null !== $lastname && '' !== $firstname && '' !== $lastname) {
            return $this->unixLogin->fromName($firstname, $lastname);
        }

        // No usable name: fall back to the directory username, normalised the same way, so the
        // result is still a login rather than whatever the directory holds.
        return $this->unixLogin->fromName($student->getUsername(), '');
    }

    /** @return list<int> */
    private function optionIdsFor(Program $program, User $student): array
    {
        $ids = [];

        foreach ($this->studentOptions->findOptionsForStudent($program, $student) as $option) {
            $id = $option->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return list<int> */
    private function modalityIdsFor(Program $program, User $student): array
    {
        $ids = [];

        foreach ($this->studentModalities->findModalitiesForStudent($program, $student) as $modality) {
            $id = $modality->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
