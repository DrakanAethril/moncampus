<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Option;
use App\Entity\User;
use App\Entity\VideoResource;
use App\Repository\ProgramStudentOptionRepository;

/**
 * A video's audience, resolved into students: the whole class, or only those holding one of the
 * selected options.
 *
 * The same rule as AudioRecordingAudienceResolver, restated rather than generalised - the audio one
 * is shipped and takes an AudioRecording, and turning it into a "media" resolver would touch the
 * listening chain to save fifteen lines here. The rule itself is short enough to be read twice.
 *
 * Same union rule as an assignment's option audience (App\Service\AssignmentAudienceResolver) - a
 * student holding SLAM is targeted by "SLAM" as much as by "SLAM + SISR" - with one difference: here
 * no option selected means "the whole class" and not "nobody", step 1 having no audience type to
 * choose, only options to tick or not.
 */
class VideoResourceAudienceResolver
{
    public function __construct(
        private readonly ProgramStudentOptionRepository $programStudentOptionRepository,
    ) {
    }

    /** @return list<User> */
    public function resolveAudience(VideoResource $resource): array
    {
        $program = $resource->getProgram();

        if (null === $program) {
            return [];
        }

        $students = $resource->getOptions()->isEmpty()
            ? $program->getStudents()->toArray()
            : $this->programStudentOptionRepository->findStudentsForProgramAndOptions($program, $resource->getOptions());

        usort($students, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));

        return $students;
    }

    public function isInAudience(VideoResource $resource, User $student): bool
    {
        foreach ($this->resolveAudience($resource) as $member) {
            if ($member->getId() === $student->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each student's options, for the SLAM/SISR tag set next to their name in the follow-up screen.
     *
     * @return array<int, list<Option>>
     */
    public function optionsByStudentId(VideoResource $resource): array
    {
        $program = $resource->getProgram();

        return null === $program ? [] : $this->programStudentOptionRepository->findOptionsByStudentForProgram($program);
    }
}
