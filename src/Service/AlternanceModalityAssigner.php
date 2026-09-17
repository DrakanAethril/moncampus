<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use App\Entity\ProgramStudentModality;
use App\Entity\User;
use App\Repository\ProgramStudentModalityRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tags a student as following their Program's alternance modality, if they aren't already.
 *
 * Creating an alternance and being tagged "en alternance" had been two separate acts, one done in
 * the UFA and the other in Formation > Paramétrage > Membres - and the second was routinely
 * forgotten, which is not cosmetic: ProgramStudentModality is what tells the platform a student is
 * an alternant (see ProgramStudentModalityRepository::findAlternanceProgramIdsForStudent()), so an
 * untagged alternant is missing from the alternance signature sheets and gets the wrong laptop-loan
 * type suggested. Every path that creates an InternshipTutorLink now goes through here.
 *
 * removeTag() is its mirror, and belongs to one gesture: ending an alternance
 * (App\Service\AlternanceTerminationService, reached from the UFA dossier and from Formation >
 * Paramétrage > Tuteurs alike). Losing the tag is the point there - it is what stops the student
 * being an alternant for App\Service\StudentAlternanceProgramResolver, and with them the « Mon
 * alternance » tab, its page and its dashboard card. Both screens write the same fact, so both
 * carry the same consequence: the tag used to survive a termination done from the Tuteurs tab,
 * which left a student an alternant with no contract behind them.
 *
 * Does not flush - callers are mid-transaction (see App\Service\AlternanceImport\ImportExecutor) or
 * about to flush their own form submission.
 */
class AlternanceModalityAssigner
{
    public function __construct(
        private readonly ProgramStudentModalityRepository $studentModalityRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return bool whether a tag was actually added (false = already tagged, or no such modality) */
    public function ensureTagged(Program $program, User $student): bool
    {
        $alternanceModality = null;
        foreach ($program->getModalities() as $modality) {
            if ($modality->isAlternance()) {
                $alternanceModality = $modality;
                break;
            }
        }

        // A Program with no alternance modality attached cannot tag anyone - that is a
        // configuration gap for staff to fix on the Formation, not something to invent here.
        if (null === $alternanceModality) {
            return false;
        }

        foreach ($this->studentModalityRepository->findAllForProgramAndStudent($program, $student) as $existing) {
            if ($existing->getModality()?->getId() === $alternanceModality->getId()) {
                return false;
            }
        }

        $this->entityManager->persist(new ProgramStudentModality($program, $student, $alternanceModality));

        return true;
    }

    /**
     * Drops whatever ties this student to this Program's alternance modality.
     *
     * Every matching row is removed rather than the first one found: a duplicate tag is invisible on
     * screen, and leaving one behind would leave the student an alternant with nothing behind it -
     * the precise state this exists to undo. Only the alternance modality is touched; a student
     * tagged with an option or another modality keeps it.
     *
     * @return bool whether anything was actually removed
     */
    public function removeTag(Program $program, User $student): bool
    {
        $removed = false;
        foreach ($this->studentModalityRepository->findAllForProgramAndStudent($program, $student) as $existing) {
            if (true === $existing->getModality()?->isAlternance()) {
                $this->entityManager->remove($existing);
                $removed = true;
            }
        }

        return $removed;
    }
}
