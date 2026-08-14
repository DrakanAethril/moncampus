<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSeancePlacement;
use App\Entity\ProgressionSequence;
use App\Entity\SeancePhaseInstance;
use App\Enum\EvaluationNature;

/**
 * Assembles the "Progression pédagogique" export - the document a teacher hands an auditor to
 * justify what is taught, in what order, and by what methods.
 *
 * The document answers three Qualiopi questions and is laid out in that order, because that is the
 * order they are asked in:
 *
 *   - what was planned, and did it happen (critère 1: information sur les objectifs et la durée);
 *   - how the objectives are broken down over the year (critère 2: adaptation aux publics, and
 *     critère 3's "séquencement");
 *   - by what methods and means each session is run (critère 3), and how learning is checked
 *     (critère 7's évaluations D/F/S).
 *
 * Everything printed is MEASURED, never asserted: hours come from the placements on real créneaux,
 * dates from those créneaux, methods from the séances' own déroulé. A document that stated round
 * numbers nobody could trace back would be worse than no document - an auditor's first question is
 * where the figure comes from.
 *
 * It reads the CLASS's copies (SequenceInstance/SeanceInstance and their phases), never the library
 * templates: what has to be justified is what this class received.
 *
 * @phpstan-type PhaseRow array{name: string, minutes: int|null, contenu: string|null, objectifs: string|null, teacher: string|null, student: string|null, means: string|null}
 * @phpstan-type SeanceRow array{title: string, date: \DateTimeImmutable|null, start: string|null, end: string|null, room: string|null, group: string|null, minutes: int, nature: EvaluationNature|null, objectifs: string|null, materials: string|null, phases: list<PhaseRow>, sequenceInstanceId: int|null, seanceInstanceId: int|null}
 * @phpstan-type SequenceRow array{title: string, position: int, seanceCount: int, deliveryCount: int, objectifs: string|null, capacites: string|null, preRequis: string|null, transversalites: string|null, situation: string|null, supports: string|null, differentiation: string|null, firstDay: \DateTimeImmutable|null, lastDay: \DateTimeImmutable|null, plannedMinutes: int, placedMinutes: int, seances: list<SeanceRow>, unplacedCount: int}
 */
class ProgressionQualiopiBuilder
{
    /**
     * @return array{
     *     progression: Progression,
     *     sequences: list<SequenceRow>,
     *     totalPlannedMinutes: int,
     *     totalPlacedMinutes: int,
     *     seanceCount: int,
     *     placedSeanceCount: int,
     *     perGroupSeanceCount: int,
     *     firstDay: \DateTimeImmutable|null,
     *     lastDay: \DateTimeImmutable|null,
     *     evaluationCounts: array<string, int>,
     *     evaluationRows: list<array{title: string, nature: EvaluationNature, date: \DateTimeImmutable|null, sequence: string|null}>,
     *     methodSummary: list<string>,
     * }
     */
    public function build(Progression $progression): array
    {
        $sequences = [];
        $totalPlanned = 0;
        $totalPlaced = 0;
        $seanceCount = 0;
        $placedSeanceCount = 0;
        $firstDay = null;
        $lastDay = null;
        $evaluationRows = [];
        $means = [];
        $perGroupSeanceCount = 0;

        foreach ($this->orderedSequences($progression) as $position => $sequence) {
            $instance = $sequence->getSequenceInstance();
            $seanceRows = [];
            $unplaced = 0;

            foreach ($sequence->getActiveSeances() as $seance) {
                ++$seanceCount;
                $placements = $seance->getActivePlacements();

                if ([] === $placements) {
                    ++$unplaced;
                    $seanceRows[] = $this->seanceRow($seance, null, $instance?->getId());
                    continue;
                }

                ++$placedSeanceCount;
                if (\count($placements) > 1) {
                    ++$perGroupSeanceCount;
                }

                // One row per placement, not per séance: a séance duplicated per groupe really is
                // taught twice, on two dates, and a document that folded them into one line would
                // under-report the hours actually delivered.
                foreach ($placements as $placement) {
                    $row = $this->seanceRow($seance, $placement, $instance?->getId());
                    $seanceRows[] = $row;

                    $day = $row['date'];
                    if (null !== $day) {
                        $firstDay = null === $firstDay || $day < $firstDay ? $day : $firstDay;
                        $lastDay = null === $lastDay || $day > $lastDay ? $day : $lastDay;
                    }
                }

                if (null !== $seance->getEvaluationNature()) {
                    $evaluationRows[] = [
                        'title' => $seance->getTitle(),
                        'nature' => $seance->getEvaluationNature(),
                        'date' => $placements[0]->getLessonSession()?->getDay(),
                        'sequence' => $sequence->getTitle(),
                    ];
                }
            }

            foreach ($seanceRows as $row) {
                foreach ($row['phases'] as $phase) {
                    if (null !== $phase['means'] && '' !== trim($phase['means'])) {
                        $means[] = trim(strip_tags($phase['means']));
                    }
                }
            }

            $planned = $sequence->getPlannedMinutes();
            $placed = $sequence->getPlacedMinutes();
            $totalPlanned += $planned;
            $totalPlaced += $placed;

            $sequences[] = [
                'title' => $sequence->getTitle(),
                'position' => $position + 1,
                // Two different counts, and the document prints both when they differ: a séance
                // taught once per groupe is ONE séance and TWO deliveries. Showing only the second
                // makes the year look longer than it is; showing only the first hides hours the
                // teacher really gave.
                'seanceCount' => \count($sequence->getActiveSeances()),
                'deliveryCount' => \count($seanceRows),
                'objectifs' => $instance?->getObjectifs(),
                'capacites' => $instance?->getCapacitesAttendues(),
                'preRequis' => $instance?->getPreRequis(),
                'transversalites' => $instance?->getTransversalites(),
                'situation' => $instance?->getSituationProblematique(),
                'supports' => $instance?->getSupportsGeneraux(),
                'differentiation' => $instance?->getDifferentiation(),
                'firstDay' => $sequence->getFirstPlacedDay(),
                'lastDay' => $sequence->getLastPlacedDay(),
                'plannedMinutes' => $planned,
                'placedMinutes' => $placed,
                'seances' => $seanceRows,
                'unplacedCount' => $unplaced,
            ];
        }

        // Posed evaluations (the Carnet de notes rows) on top of the ones the séances carry - both
        // are evidence under critère 7, and the document must not show one kind only.
        foreach ($progression->getTopic()?->getEvaluations() ?? [] as $evaluation) {
            $nature = $evaluation->getNature();
            if (null === $nature || null !== $evaluation->getInactiveDate()) {
                continue;
            }

            $evaluationRows[] = [
                'title' => $evaluation->getName(),
                'nature' => $nature,
                'date' => $evaluation->getDate(),
                'sequence' => $evaluation->getProgressionSequence()?->getTitle(),
            ];
        }

        usort($evaluationRows, static fn (array $a, array $b): int => [$a['date']?->format('Y-m-d') ?? '9999'] <=> [$b['date']?->format('Y-m-d') ?? '9999']);

        return [
            'progression' => $progression,
            'sequences' => $sequences,
            'totalPlannedMinutes' => $totalPlanned,
            'totalPlacedMinutes' => $totalPlaced,
            'seanceCount' => $seanceCount,
            'placedSeanceCount' => $placedSeanceCount,
            'firstDay' => $firstDay,
            'lastDay' => $lastDay,
            'perGroupSeanceCount' => $perGroupSeanceCount,
            'evaluationCounts' => $this->countByNature($evaluationRows),
            'evaluationRows' => $evaluationRows,
            'methodSummary' => $this->distinctMeans($means),
        ];
    }

    /**
     * @param list<array{nature: EvaluationNature, ...}> $rows
     *
     * @return array<string, int>
     */
    private function countByNature(array $rows): array
    {
        $counts = [
            EvaluationNature::Diagnostic->value => 0,
            EvaluationNature::Formative->value => 0,
            EvaluationNature::Summative->value => 0,
        ];

        foreach ($rows as $row) {
            ++$counts[$row['nature']->value];
        }

        return $counts;
    }

    /**
     * The "moyens et supports" actually named across the year, deduplicated - the document's
     * evidence for critère 3 in one place, so an auditor does not have to read forty séance rows to
     * find out what the class is taught with.
     *
     * Capped, because this is a summary and not an inventory: past a couple of dozen entries the
     * list stops being read, and the per-séance detail below it is the exhaustive source anyway.
     *
     * @param list<string> $means
     *
     * @return list<string>
     */
    private function distinctMeans(array $means): array
    {
        $seen = [];
        foreach ($means as $entry) {
            $key = mb_strtolower($entry);
            if ('' !== $key && !isset($seen[$key])) {
                $seen[$key] = $entry;
            }
        }

        return \array_slice(array_values($seen), 0, 24);
    }

    /**
     * One printed line. A null placement is a séance the progression carries but has not put on a
     * créneau yet - it still belongs in the document, as the plan, and the summary counts it as
     * unplaced rather than hiding it.
     *
     * @return SeanceRow
     */
    private function seanceRow(ProgressionSeance $seance, ?ProgressionSeancePlacement $placement, ?int $sequenceInstanceId): array
    {
        $session = $placement?->getLessonSession();
        $start = $session?->getStartHour();
        $end = $session?->getEndHour();
        $instance = $seance->getSeanceInstance();

        return [
            'title' => $seance->getTitle(),
            'date' => $session?->getDay(),
            'start' => $start?->format('H:i'),
            'end' => $end?->format('H:i'),
            'room' => $session?->getClassRoom()?->getName(),
            'group' => $placement?->getOption()?->getShortName(),
            'minutes' => $placement?->getDurationMinutes() ?? $seance->getPlannedMinutesOrZero(),
            'nature' => $seance->getEvaluationNature(),
            'objectifs' => $instance?->getObjectifs(),
            'materials' => $instance?->getMaterials(),
            'phases' => $this->phaseRows($seance),
            'sequenceInstanceId' => $sequenceInstanceId,
            'seanceInstanceId' => $instance?->getId(),
        ];
    }

    /** @return list<PhaseRow> */
    private function phaseRows(ProgressionSeance $seance): array
    {
        $instance = $seance->getSeanceInstance();
        if (null === $instance) {
            return [];
        }

        $phases = $instance->getSeancePhaseInstances()->toArray();
        usort($phases, static fn (SeancePhaseInstance $a, SeancePhaseInstance $b): int => $a->getOrdre() <=> $b->getOrdre());

        return array_map(
            static fn (SeancePhaseInstance $phase): array => [
                'name' => $phase->getNom() ?? '—',
                'minutes' => null === $phase->getDuree() ? null : (int) round((float) $phase->getDuree()),
                'contenu' => $phase->getContenu(),
                'objectifs' => $phase->getObjectifs(),
                'teacher' => $phase->getEnseignant(),
                'student' => $phase->getEtudiant(),
                'means' => $phase->getMoyensSupports(),
            ],
            $phases,
        );
    }

    /** @return list<ProgressionSequence> */
    private function orderedSequences(Progression $progression): array
    {
        $sequences = $progression->getSequences()->toArray();
        usort($sequences, static fn (ProgressionSequence $a, ProgressionSequence $b): int => $a->getPosition() <=> $b->getPosition());

        return $sequences;
    }
}
