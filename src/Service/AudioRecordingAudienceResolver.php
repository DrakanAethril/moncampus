<?php

namespace App\Service;

use App\Entity\AudioRecording;
use App\Entity\Option;
use App\Entity\User;
use App\Repository\ProgramStudentOptionRepository;

/**
 * A recording's audience, resolved into students: the whole class, or only those holding one of the
 * selected options.
 *
 * Same union rule as an assignment's option audience (App\Service\AssignmentAudienceResolver) - a
 * student holding SLAM is targeted by "SLAM" as much as by "SLAM + SISR" - with one difference: here
 * no option selected means "the whole class" and not "nobody", step 1 having no audience type to
 * choose, only options to tick or not.
 */
class AudioRecordingAudienceResolver
{
    public function __construct(
        private readonly ProgramStudentOptionRepository $programStudentOptionRepository,
    ) {
    }

    /** @return list<User> */
    public function resolveAudience(AudioRecording $recording): array
    {
        $program = $recording->getProgram();

        if (null === $program) {
            return [];
        }

        $students = $recording->getOptions()->isEmpty()
            ? $program->getStudents()->toArray()
            : $this->programStudentOptionRepository->findStudentsForProgramAndOptions($program, $recording->getOptions());

        usort($students, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));

        return $students;
    }

    public function isInAudience(AudioRecording $recording, User $student): bool
    {
        foreach ($this->resolveAudience($recording) as $member) {
            if ($member->getId() === $student->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each student's options, for the SLAM/SISR tag set next to their name in step 2 and in the
     * statistics.
     *
     * @return array<int, list<Option>>
     */
    public function optionsByStudentId(AudioRecording $recording): array
    {
        $program = $recording->getProgram();

        return null === $program ? [] : $this->programStudentOptionRepository->findOptionsByStudentForProgram($program);
    }
}
