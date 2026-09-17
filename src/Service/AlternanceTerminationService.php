<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Enum\UfaActivityType;
use App\Repository\InternshipTutorLinkRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What « Terminer l'alternance » means, in one place: the contract is over, so the platform stops
 * asking anyone anything about it and the student stops being an alternant.
 *
 * Two writes, and neither of them deletes:
 *  - InternshipTutorLink::$inactiveDate is stamped. That single column is what every list already
 *    reads to decide whether an alternance is live (see InternshipTutorLinkRepository), which is why
 *    terminating needed no new state: the tutor's portal, « Mon alternance », the pending counts and
 *    the KPI cards all go quiet at once, and AlternancePeriodWizardService turns the three wizards
 *    read-only. The row, its engagement and every evaluation already signed stay readable - the
 *    livret of a terminated alternance is still consultable, which is the whole point of not
 *    deleting.
 *  - the student's alternance modality tag is dropped (AlternanceModalityAssigner::removeTag()).
 *    That is the half a date cannot express: being tagged is what makes someone an alternant for
 *    StudentAlternanceProgramResolver, and an untagged student loses the « Mon alternance » tab, its
 *    page and its dashboard card. It also means the platform stops counting them as an alternant
 *    anywhere that reads the tag live, the laptop-loan type suggestion included.
 *
 * resume() is the exact inverse, and exists because the pair has to hold: an alternance put back in
 * service whose student stayed untagged would be live for the tutor and invisible to the student.
 *
 * Two screens end an alternance - the UFA dossier's own « Terminer l'alternance » and the row action
 * of Formation > Paramétrage > Tuteurs - and both come through here, because they write the same
 * fact and must therefore carry the same consequence. Only the UFA dashboard offers resume().
 */
class AlternanceTerminationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InternshipTutorLinkRepository $tutorLinkRepository,
        private readonly AlternanceModalityAssigner $modalityAssigner,
        private readonly UfaActivityRecorder $activityRecorder,
    ) {
    }

    /** @return bool false when the alternance was already terminated - the gesture is idempotent */
    public function terminate(InternshipTutorLink $tutorLink, User $actor): bool
    {
        if ($tutorLink->isTerminated()) {
            return false;
        }

        $this->untag($tutorLink);

        $tutorLink->setInactiveDate(new \DateTimeImmutable());
        $tutorLink->setInactivatedBy($actor);
        $this->entityManager->flush();

        // After the flush, like every other call to the recorder: the journal reports the gesture,
        // it does not take part in it.
        $this->activityRecorder->record(UfaActivityType::AlternanceTerminated, $tutorLink, $actor);

        return true;
    }

    /** @return bool false when the alternance was already running */
    public function resume(InternshipTutorLink $tutorLink, User $actor): bool
    {
        if (!$tutorLink->isTerminated()) {
            return false;
        }

        $tutorLink->setInactiveDate(null);
        $tutorLink->setInactivatedBy(null);

        $program = $tutorLink->getProgram();
        $student = $tutorLink->getStudent();
        if (null !== $program && null !== $student) {
            $this->modalityAssigner->ensureTagged($program, $student);
        }

        $this->entityManager->flush();

        $this->activityRecorder->record(UfaActivityType::AlternanceResumed, $tutorLink, $actor);

        return true;
    }

    // The tag belongs to a (Program, student) pair, not to this link, so it is only dropped once no
    // OTHER live alternance of that student on that formation still needs it. Two concurrent
    // contracts on one formation are not a shape the UFA creates, but reading the tag as shared is
    // what keeps terminating one from quietly ending the other as well. Called before $inactiveDate
    // is stamped, so the question is asked of a database that still agrees with the object.
    private function untag(InternshipTutorLink $tutorLink): void
    {
        $program = $tutorLink->getProgram();
        $student = $tutorLink->getStudent();

        if (null === $program || null === $student) {
            return;
        }

        foreach ($this->tutorLinkRepository->findAllActiveForProgram($program) as $other) {
            if ($other !== $tutorLink && $other->getStudent() === $student) {
                return;
            }
        }

        $this->modalityAssigner->removeTag($program, $student);
    }
}
