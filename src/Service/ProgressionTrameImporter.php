<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FileLibraryNode;
use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSequence;
use App\Entity\SequenceInstance;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\ProgressionSlotTopicScope;
use App\Enum\ProgressionTrameAction;
use App\Repository\ProgressionSequenceRepository;
use App\Repository\SequenceInstanceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Reprendre la trame » - a colleague's progression, without its dates
 * (design/validated/content-sharing-between-teachers.md, "The progression trame").
 *
 * The request settles what « partager ma progression » means: **the trame, without the créneaux**.
 * The recipient receives the shape of the plan and rebuilds its calendar against their own
 * timetable, because a date is a property of a timetable and a colleague's timetable is not yours.
 *
 * What travels: the ordered séquences with their slot rules, and per séquence the ordered séance
 * rows - title, planned minutes, evaluation nature, per-group. What does not: `forcedStartDate`,
 * every `ProgressionSeancePlacement`, every snapshot hour. The placements are **computed** at the
 * end, by App\Service\ProgressionPlacementService::replan(), on the recipient's own timetable.
 *
 * **The four constraints of the model are answered before the click, never after it**
 * (App\Enum\ProgressionTrameAction): a deleted template, an instance the class already has, a
 * séquence another progression is already teaching, and - on the screen rather than here - a
 * recipient with no matière free. analyse() is what the confirmation screen draws, and import()
 * re-runs it rather than trusting it: between the GET and the POST a colleague may have planned one
 * of those séquences.
 *
 * The quota is asked **once, with the sum of every séquence that will be duplicated**. Asking per
 * séquence is the same mistake as asking per file, one level up: the third would be refused after
 * the first two had been written.
 *
 * @phpstan-type TrameLine array{
 *     title: string,
 *     seanceCount: int,
 *     action: ProgressionTrameAction,
 *     source: ProgressionSequence,
 *     fileCount: int,
 *     totalBytes: int,
 * }
 * @phpstan-type TrameAnalysis array{lines: list<TrameLine>, keptCount: int, fileCount: int, totalBytes: int}
 */
class ProgressionTrameImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SequenceDuplicator $sequences,
        private readonly SequenceInstantiationService $instantiation,
        private readonly SequenceInstanceRepository $sequenceInstances,
        private readonly ProgressionSequenceRepository $progressionSequences,
        private readonly ProgressionPlacementService $placement,
        private readonly FileLibraryQuota $quota,
    ) {
    }

    /**
     * What each séquence of the trame will do, and what the whole thing weighs - the confirmation
     * screen's table, line for line.
     *
     * @return TrameAnalysis
     */
    public function analyse(Progression $source, Program $program): array
    {
        $lines = [];
        $fileCount = 0;
        $totalBytes = 0;
        $keptCount = 0;

        foreach ($source->getSequences() as $sequence) {
            $instance = $sequence->getSequenceInstance();

            if (null === $instance) {
                continue;
            }

            $template = $instance->getSourceTemplate();
            $existing = null === $template ? null : $this->sequenceInstances->findOneBy(['sourceTemplate' => $template, 'program' => $program]);

            $action = match (true) {
                // Already carried by another progression of this class: a SequenceInstance is planned
                // once for the whole class, so this one is left out - and named.
                null !== $existing && $this->progressionSequences->isInstancePlanned($existing) => ProgressionTrameAction::Skipped,
                null !== $existing => ProgressionTrameAction::Reused,
                null === $template => ProgressionTrameAction::Detached,
                default => ProgressionTrameAction::Copied,
            };

            $line = [
                'title' => (string) $instance->getTitre(),
                'seanceCount' => $sequence->getSeances()->count(),
                'action' => $action,
                'source' => $sequence,
                'fileCount' => 0,
                'totalBytes' => 0,
            ];

            // Only the copied ones write into the library, so only they weigh - and `Copied` is by
            // construction the branch where the template is still there.
            if (ProgressionTrameAction::Copied === $action) {
                $plan = $this->sequences->plan($template);
                $line['fileCount'] = $plan['fileCount'];
                $line['totalBytes'] = $plan['totalBytes'];
                $fileCount += $plan['fileCount'];
                $totalBytes += $plan['totalBytes'];
            }

            if (!$action->isSkipped()) {
                ++$keptCount;
            }

            $lines[] = $line;
        }

        return ['lines' => $lines, 'keptCount' => $keptCount, 'fileCount' => $fileCount, 'totalBytes' => $totalBytes];
    }

    /**
     * @throws ContentShareQuotaException when it does not fit; nothing has been written
     */
    public function import(Progression $source, User $recipient, Topic $topic, ?FileLibraryNode $destination): Progression
    {
        $program = $topic->getProgram() ?? throw new \LogicException('A matière always belongs to a formation.');
        $analysis = $this->analyse($source, $program);

        // Once, with the sum, and before a single row or object exists.
        if (!$this->quota->accepts($recipient, $analysis['totalBytes'])) {
            throw new ContentShareQuotaException($analysis['totalBytes'], $this->quota->remainingBytes($recipient));
        }

        $progression = $this->entityManager->wrapInTransaction(function () use ($analysis, $recipient, $topic, $program, $destination): Progression {
            $progression = new Progression($topic, $recipient);
            $this->entityManager->persist($progression);

            $position = 0;

            foreach ($analysis['lines'] as $line) {
                if ($line['action']->isSkipped()) {
                    continue;
                }

                $instance = $this->instanceFor($line, $recipient, $program, $destination);

                if (null === $instance) {
                    continue;
                }

                $this->copySequence($line['source'], $progression, $instance, $position++);
            }

            $this->entityManager->flush();

            return $progression;
        });

        // The placements are computed, not copied: on the recipient's own timetable, by the ordinary
        // calculation, as soon as the trame exists.
        $this->placement->replan($progression);
        $this->entityManager->flush();

        return $progression;
    }

    /**
     * The SequenceInstance this class will teach - reused when it already exists, built from a
     * library copy otherwise, and copied straight from the author's instance when the template it
     * came from has been deleted since.
     *
     * @param TrameLine $line
     */
    private function instanceFor(array $line, User $recipient, Program $program, ?FileLibraryNode $destination): ?SequenceInstance
    {
        $sourceInstance = $line['source']->getSequenceInstance();

        if (null === $sourceInstance) {
            return null;
        }

        $template = $sourceInstance->getSourceTemplate();

        if (ProgressionTrameAction::Reused === $line['action'] && null !== $template) {
            return $this->sequenceInstances->findOneBy(['sourceTemplate' => $template, 'program' => $program]);
        }

        if (ProgressionTrameAction::Detached === $line['action'] || null === $template) {
            // No template to go through: the instance carries its own copy of every text field, so
            // the class-level content survives and the recipient gets nothing new in their library.
            return $this->instantiation->instantiateFromInstance($sourceInstance, $program, $recipient);
        }

        $copy = $this->sequences->duplicateWithinBudget($template, $recipient, $destination);

        return $this->instantiation->instantiateSequence($copy, $program, $recipient);
    }

    /**
     * The trame of one séquence: its slot rules and its ordered séance rows. **No date**, and no
     * placement - `forcedStartDate` stays null, which is « Automatique ».
     */
    private function copySequence(ProgressionSequence $source, Progression $progression, SequenceInstance $instance, int $position): void
    {
        $sequence = new ProgressionSequence($progression, $instance);
        $sequence->setPosition($position);
        $sequence->setPlaceInTimetable($source->isPlaceInTimetable());
        $sequence->setSlotComposition($source->getSlotComposition());
        $sequence->setOneSeancePerWeek($source->isOneSeancePerWeek());

        // « Uniquement les créneaux de la matière XX » names a matière of the **author's** class, and
        // it means nothing in the recipient's. The scope falls back to their own matière rather than
        // pointing at a Topic they do not teach.
        $slotTopic = $source->getSlotTopic();
        $sameProgram = null !== $slotTopic && $slotTopic->getProgram() === $instance->getProgram();
        $sequence->setSlotTopicScope($sameProgram ? $source->getSlotTopicScope() : ProgressionSlotTopicScope::Own);
        $sequence->setSlotTopic($sameProgram ? $slotTopic : null);

        $this->entityManager->persist($sequence);

        $seanceInstances = array_values($instance->getSeanceInstances()->toArray());
        $seancePosition = 0;

        foreach ($source->getSeances() as $sourceSeance) {
            $seance = new ProgressionSeance($sequence, $sourceSeance->getTitle());
            // Linked to the instance's séance of the same rank when there is one: the trame's line
            // order is the séquence's own, and a séance added for the author's class alone has no
            // counterpart at all - which the nullable link is there for.
            $seance->setSeanceInstance($seanceInstances[$seancePosition] ?? null);
            $seance->setPosition($seancePosition);
            $seance->setPlannedMinutes($sourceSeance->getPlannedMinutes());
            $seance->setEvaluationNature($sourceSeance->getEvaluationNature());
            $seance->setPerGroup($sourceSeance->isPerGroup());

            $this->entityManager->persist($seance);
            ++$seancePosition;
        }
    }
}
