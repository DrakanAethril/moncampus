<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentModalityRepository;

/**
 * "Which Program is this student an alternant in, if any?" - the single question behind the
 * "Mon alternance" tab, the page it opens, and the dashboard card.
 *
 * Asked in one place because the three used to answer it independently: the tab appeared for every
 * student of a Program with the alternance feature on, while the page picked its Program the same
 * way and 404'd, and the card demanded a tutor link on top. A student on the classic track in a
 * class that also runs an alternance track therefore got a tab leading nowhere.
 *
 * Two conditions, both required:
 *  - the student is tagged with the Program's alternance modality (ProgramStudentModality +
 *    Modality::$isAlternance) - that is what makes THIS student an alternant rather than a
 *    classmate of some;
 *  - the Program has the alternance feature enabled, without which there is no data to show at
 *    all. It can only ever make the tab rarer, never wrongly present.
 */
class StudentAlternanceProgramResolver
{
    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly ProgramStudentModalityRepository $studentModalityRepository,
    ) {
    }

    public function resolve(User $student): ?Program
    {
        // findAllActiveForStudent() is already scoped to the student's side of the test fence, so a
        // test account resolves against test formations only, for free.
        $programs = $this->programRepository->findAllActiveForStudent($student);

        if ([] === $programs) {
            return null;
        }

        $alternanceProgramIds = $this->studentModalityRepository->findAlternanceProgramIdsForStudent($student);

        foreach ($programs as $program) {
            if ($program->isInternshipManagementEnabled() && isset($alternanceProgramIds[(int) $program->getId()])) {
                return $program;
            }
        }

        return null;
    }
}
