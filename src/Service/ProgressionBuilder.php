<?php

namespace App\Service;

use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSequence;
use App\Entity\SequenceInstance;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a picked SequenceInstance into a progression row plus its per-class séance lines.
 *
 * Split out of App\Service\ProgressionPlacementService on purpose: this one only ever *creates*
 * rows from library content, the other one only ever decides *where they land*. Keeping them apart
 * is what lets replan() be called freely from anywhere without any risk of it re-materialising
 * séances a teacher deliberately removed.
 */
class ProgressionBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgressionPlacementService $placementService,
    ) {
    }

    /**
     * Appends a séquence at the end of the progression and copies its instantiated séances into
     * per-class lines. Titles and durations are copied, not referenced (the same reasoning as
     * SeanceInstance vs SeanceTemplate: editing this class's planning must not rewrite content
     * another class's progression reads).
     */
    public function addSequence(
        Progression $progression,
        SequenceInstance $sequenceInstance,
        ?\DateTimeImmutable $forcedStartDate = null,
        bool $placeInTimetable = true,
    ): ProgressionSequence {
        // Computed before the constructor runs - it registers the new row on the inverse side, so
        // asking for "the highest position so far" afterwards would already count this one.
        $position = $this->nextSequencePosition($progression);

        $sequence = new ProgressionSequence($progression, $sequenceInstance);
        $sequence->setPosition($position);
        $sequence->setForcedStartDate($forcedStartDate);
        $sequence->setPlaceInTimetable($placeInTimetable);

        $seancePosition = 0;
        foreach ($sequenceInstance->getSeanceInstances() as $seanceInstance) {
            $seance = new ProgressionSeance($sequence, $seanceInstance->getTitre() ?? '');
            $seance->setSeanceInstance($seanceInstance);
            $seance->setPlannedDuration($seanceInstance->getDuree());
            $seance->setPosition($seancePosition++);
            $this->entityManager->persist($seance);
        }

        $this->entityManager->persist($sequence);

        return $sequence;
    }

    /**
     * Screen 2a's "+ Ajouter une séance à la séquence" - a séance that exists for this class only
     * and has no library counterpart, hence the null SeanceInstance.
     */
    public function addAdHocSeance(ProgressionSequence $sequence, string $title, ?float $duration): ProgressionSeance
    {
        $seance = new ProgressionSeance($sequence, $title);
        $seance->setPosition($this->nextSeancePosition($sequence));

        if (null !== $duration) {
            $seance->setPlannedDuration(number_format($duration, 2, '.', ''));
        }

        $this->entityManager->persist($seance);

        return $seance;
    }

    /**
     * Applies a new séquence order coming from the drag-and-drop handle, then replans - §4.8's
     * "réordonner replanifie les séquences suivantes".
     *
     * Ids that don't belong to this progression are ignored rather than trusted, same reasoning as
     * every other reorder endpoint in the app.
     *
     * @param list<int> $orderedIds
     */
    public function reorderSequences(Progression $progression, array $orderedIds): void
    {
        $byId = [];
        foreach ($progression->getSequences() as $sequence) {
            $byId[(int) $sequence->getId()] = $sequence;
        }

        $position = 0;
        foreach ($orderedIds as $id) {
            if (isset($byId[$id])) {
                $byId[$id]->setPosition($position++);
                unset($byId[$id]);
            }
        }

        // Anything the client didn't mention keeps a stable relative order at the end.
        foreach ($byId as $sequence) {
            $sequence->setPosition($position++);
        }

        $this->placementService->replan($progression);
    }

    /**
     * Same for the séances inside one séquence - on 2a "l'association suit l'ordre", so reordering
     * is immediately followed by a replan.
     *
     * @param list<int> $orderedIds
     */
    public function reorderSeances(ProgressionSequence $sequence, array $orderedIds): void
    {
        $byId = [];
        foreach ($sequence->getSeances() as $seance) {
            $byId[(int) $seance->getId()] = $seance;
        }

        $position = 0;
        foreach ($orderedIds as $id) {
            if (isset($byId[$id])) {
                $byId[$id]->setPosition($position++);
                unset($byId[$id]);
            }
        }

        foreach ($byId as $seance) {
            $seance->setPosition($position++);
        }

        $progression = $sequence->getProgression();
        if (null !== $progression) {
            $this->placementService->replan($progression);
        }
    }

    /**
     * §4.8 - dropping a séquence frees its créneaux (orphanRemoval takes the placements with it)
     * and the following séquences slide up.
     */
    public function removeSequence(ProgressionSequence $sequence): void
    {
        $progression = $sequence->getProgression();
        $progression?->removeSequence($sequence);
        $this->entityManager->remove($sequence);
        $this->entityManager->flush();

        if (null !== $progression) {
            $this->resequence($progression);
            $this->placementService->replan($progression);
        }
    }

    private function resequence(Progression $progression): void
    {
        $position = 0;
        foreach ($progression->getSequences() as $sequence) {
            $sequence->setPosition($position++);
        }
    }

    private function nextSequencePosition(Progression $progression): int
    {
        $max = -1;
        foreach ($progression->getSequences() as $sequence) {
            $max = max($max, $sequence->getPosition());
        }

        return $max + 1;
    }

    private function nextSeancePosition(ProgressionSequence $sequence): int
    {
        $max = -1;
        foreach ($sequence->getSeances() as $seance) {
            $max = max($max, $seance->getPosition());
        }

        return $max + 1;
    }
}
