<?php

namespace App\Service;

use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSequence;
use App\Entity\SequenceInstance;
use App\Repository\ProgressionSequenceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a séquence instantiated for a class, and everything that only existed because of it.
 *
 * Instantiation is a one-way frozen copy (App\Service\SequenceInstantiationService), so undoing it
 * has to walk the same chain back down: the progression rows that planned it, the créneaux those
 * rows had taken, and the séance copies themselves. Doing it in a service rather than the
 * controller because the order matters and the progression has to be replanned afterwards.
 *
 * What it deliberately does NOT touch: the lesson logs of the créneaux involved. A cahier de texte
 * is the teacher's own record of a lesson that really happened - "pré-remplir" only ever gave it a
 * starting point and the two have been independent since (see LessonLogController::preRemplir()).
 * The créneaux themselves are never deleted either: they belong to the timetable, which is
 * staff-owned, and this module only ever borrows them.
 */
class SequenceInstanceRemover
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgressionSequenceRepository $progressionSequenceRepository,
        private readonly ProgressionPlacementService $placementService,
    ) {
    }

    public function remove(SequenceInstance $sequenceInstance): void
    {
        $progressions = [];

        foreach ($this->progressionSequenceRepository->findBy(['sequenceInstance' => $sequenceInstance]) as $progressionSequence) {
            $progression = $progressionSequence->getProgression();
            if (null !== $progression) {
                $progressions[(int) $progression->getId()] = $progression;
            }

            $this->releaseSlots($progressionSequence);

            $progression?->removeSequence($progressionSequence);
            $this->entityManager->remove($progressionSequence);
        }

        // The séance copies and their phases/resources go with the séquence (orphanRemoval on
        // SeanceInstance's own collections takes the phases and resource copies).
        foreach ($sequenceInstance->getSeanceInstances()->toArray() as $seanceInstance) {
            $this->entityManager->remove($seanceInstance);
        }

        $this->entityManager->remove($sequenceInstance);
        $this->entityManager->flush();

        // Replanned last, on a progression that no longer knows this séquence: the séquences after
        // it slide up and take the créneaux just freed, which is the same behaviour as removing a
        // séquence from the progression screen itself (§4.8).
        foreach ($progressions as $progression) {
            $this->resequence($progression);
            $this->placementService->replan($progression);
        }

        $this->entityManager->flush();
    }

    /**
     * Frees every créneau this séquence had taken. The placements themselves disappear with the
     * rows, so the only thing needing an explicit undo is the title "Valider le placement" wrote
     * onto the créneau - left alone it would keep advertising a séance that no longer exists.
     *
     * Only cleared when it still IS that title: a staff member who has since renamed the créneau
     * by hand has said something the progression has no business overwriting. The créneau's matière
     * is never touched - it is a timetable fact, true whether or not this séquence planned it.
     */
    private function releaseSlots(ProgressionSequence $progressionSequence): void
    {
        foreach ($progressionSequence->getSeances() as $seance) {
            $placements = $seance->getActivePlacements();
            $partCount = \count($placements);

            foreach ($placements as $placement) {
                $session = $placement->getLessonSession();
                if (null === $session) {
                    continue;
                }

                if ($session->getTitle() === $this->writtenTitle($seance, $placement->getPartIndex(), $partCount)) {
                    $session->setTitle(null);
                }

                // The séance's library copy is about to go, so the unique OneToOne that made the
                // créneau "programmée" has to go with it.
                $seance->getSeanceInstance()?->setLessonSession(null);
            }
        }
    }

    // Mirror of ProgressionPlacementService::sessionTitleFor() - kept in step with it by the test
    // that walks a validated séquence through this whole removal.
    private function writtenTitle(ProgressionSeance $seance, int $partIndex, int $partCount): string
    {
        return $partCount > 1
            ? \sprintf('%s (%d/%d)', $seance->getTitle(), $partIndex + 1, $partCount)
            : $seance->getTitle();
    }

    private function resequence(Progression $progression): void
    {
        $position = 0;
        foreach ($progression->getSequences() as $sequence) {
            $sequence->setPosition($position++);
        }
    }
}
