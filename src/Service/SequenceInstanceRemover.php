<?php

namespace App\Service;

use App\Entity\Progression;
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
 * starting point et les deux sont indépendants depuis (voir le bouton « Pré-remplir » du cahier
 * de texte, assets/controllers/lesson_log_prefill_controller.js).
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

            $this->placementService->releaseSequence($progressionSequence);

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

    private function resequence(Progression $progression): void
    {
        $position = 0;
        foreach ($progression->getSequences() as $sequence) {
            $sequence->setPosition($position++);
        }
    }
}
