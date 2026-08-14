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
 * The reverse is deliberately NOT done: deactivating an alternance does not untag the student, who
 * followed the modality for that year whatever happened to the contract.
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
}
