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
 * **Every volume printed is a LEARNER volume**, and the document says so. There are two possible
 * readings of "how many hours is this progression" and mixing them is what makes a document
 * unusable at an audit: the face-à-face a teacher gives (a séance dédoublée par groupe costs its
 * duration twice) and what one apprenant receives (they attend their own group's delivery, once).
 * Printing the first against the second produced lines like "44 h 05 placés sur 28 h prévus", which
 * reads as an error rather than as two different questions. So the choice is made here, once:
 * learner hours everywhere, and the duplication is named where it happens - on the créneau detail
 * of the séance concerned ("redispensée le … au groupe …"), which is where an auditor asks about it.
 *
 * @phpstan-type PhaseRow array{name: string, minutes: int|null, contenu: string|null, objectifs: string|null, teacher: string|null, student: string|null, means: string|null}
 * @phpstan-type DeliveryRow array{date: \DateTimeImmutable|null, start: string|null, end: string|null, room: string|null, group: string|null, groupKey: string, minutes: int}
 * @phpstan-type SeanceRow array{title: string, deliveries: list<DeliveryRow>, proposals: list<DeliveryRow>, redeliveries: list<DeliveryRow>, plannedMinutes: int, learnerMinutes: int, nature: EvaluationNature|null, objectifs: string|null, materials: string|null, phases: list<PhaseRow>, sequenceInstanceId: int|null, seanceInstanceId: int|null}
 * @phpstan-type SequenceRow array{title: string, position: int, seanceCount: int, objectifs: string|null, capacites: string|null, preRequis: string|null, transversalites: string|null, situation: string|null, supports: string|null, differentiation: string|null, firstDay: \DateTimeImmutable|null, lastDay: \DateTimeImmutable|null, plannedMinutes: int, learnerMinutes: int, seances: list<SeanceRow>, unplacedCount: int}
 */
class ProgressionQualiopiBuilder
{
    /**
     * @return array{
     *     progression: Progression,
     *     sequences: list<SequenceRow>,
     *     totalPlannedMinutes: int,
     *     totalLearnerMinutes: int,
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
        $totalLearner = 0;
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
            $sequenceLearner = 0;
            $sequenceFirst = null;
            $sequenceLast = null;

            foreach ($sequence->getActiveSeances() as $seance) {
                ++$seanceCount;

                // VALIDATED placements only, the same reading as the class's instantiation
                // inventory (ProgressionSeancePlacementRepository::findScheduledSeanceInstanceIds
                // ForProgram()). An unconfirmed placement is the auto-planner's proposal: nobody
                // has agreed to it, and the next replan wipes and recomputes it
                // (ProgressionPlacementService::clearUnconfirmedPlacements()). Printing one as an
                // hour delivered on a dated créneau would put in an audit file a fact that can
                // change by itself - so the document counts what a teacher validated, and shows
                // the rest as what it is, below.
                $placements = [];
                $proposals = [];
                foreach ($seance->getActivePlacements() as $placement) {
                    if ($placement->isConfirmed()) {
                        $placements[] = $placement;
                        continue;
                    }

                    $proposals[] = $placement;
                }

                // ONE row per séance, whatever it took to deliver it. A séance dédoublée par groupe
                // is still one séance of the progression - printing it twice made the year look
                // longer than it is. The deliveries it actually took are carried inside the row and
                // named there instead, which is where an auditor asks the question.
                $row = $this->seanceRow($seance, $placements, $proposals, $instance?->getId());
                $seanceRows[] = $row;
                $sequenceLearner += $row['learnerMinutes'];

                if ([] === $placements) {
                    ++$unplaced;
                    continue;
                }

                ++$placedSeanceCount;
                if ([] !== $row['redeliveries']) {
                    ++$perGroupSeanceCount;
                }

                foreach ($row['deliveries'] as $delivery) {
                    $day = $delivery['date'];
                    if (null !== $day) {
                        $firstDay = null === $firstDay || $day < $firstDay ? $day : $firstDay;
                        $lastDay = null === $lastDay || $day > $lastDay ? $day : $lastDay;
                        // The séquence's own period is read from the SAME deliveries as the year's,
                        // rather than from ProgressionSequence::getFirstPlacedDay(), which counts
                        // every placement including the unvalidated ones. Mixing the two printed a
                        // séquence "du 01/09 au 07/09" three lines under "aucune séance encore
                        // placée à l'emploi du temps".
                        $sequenceFirst = null === $sequenceFirst || $day < $sequenceFirst ? $day : $sequenceFirst;
                        $sequenceLast = null === $sequenceLast || $day > $sequenceLast ? $day : $sequenceLast;
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
                    foreach ($this->meansEntries($phase['means']) as $entry) {
                        $means[] = $entry;
                    }
                }
            }

            $planned = $sequence->getPlannedMinutes();
            $totalPlanned += $planned;
            $totalLearner += $sequenceLearner;

            $sequences[] = [
                'title' => $sequence->getTitle(),
                'position' => $position + 1,
                // ONE count, of séances - never of deliveries. A séance taught once per groupe is
                // one séance of the progression; the créneaux it took are named on its own row.
                'seanceCount' => \count($sequence->getActiveSeances()),
                'objectifs' => $instance?->getObjectifs(),
                'capacites' => $instance?->getCapacitesAttendues(),
                'preRequis' => $instance?->getPreRequis(),
                'transversalites' => $instance?->getTransversalites(),
                'situation' => $instance?->getSituationProblematique(),
                'supports' => $instance?->getSupportsGeneraux(),
                'differentiation' => $instance?->getDifferentiation(),
                'firstDay' => $sequenceFirst,
                'lastDay' => $sequenceLast,
                'plannedMinutes' => $planned,
                'learnerMinutes' => $sequenceLearner,
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
            'totalLearnerMinutes' => $totalLearner,
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
     * The individual "moyens et supports" a phase names, one per returned entry.
     *
     * The field is rich text, so a teacher listing three supports writes three paragraphs or three
     * bullets rather than three rows - and `strip_tags()` alone glued them into one string
     * ("VidéoprojecteurPoste élèveSupport de cours"), which the summary then printed as a single
     * unmatchable entry. Block boundaries therefore become line breaks BEFORE the tags go, and the
     * result is split on them (and on the semicolons a teacher separates a one-line list with -
     * commas are deliberately left alone, since they occur inside a single support's own wording
     * far more often than between two of them).
     *
     * @return list<string>
     */
    private function meansEntries(?string $means): array
    {
        if (null === $means || '' === trim($means)) {
            return [];
        }

        $text = preg_replace('#<(br|/p|/li|/div|/h[1-6]|/tr)\b[^>]*>#i', "\n", $means) ?? $means;
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        $entries = [];
        foreach (preg_split('/[\r\n;]+/u', $text) ?: [] as $entry) {
            // Leading list markers (the teacher's own dashes and bullets) and trailing punctuation
            // are typography, not part of the support's name - two spellings of one support must
            // not survive as two entries because one of them was written inside a bulleted list.
            $entry = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $entry)));
            $entry = trim($entry, " \t-–—*•·.,:;");

            if ('' !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The "moyens et supports" actually named across the year, deduplicated - the document's
     * evidence for critère 3 in one place, so an auditor does not have to read forty séance rows to
     * find out what the class is taught with.
     *
     * Deduplication is on a FOLDED key (lowercase, accents removed, punctuation and spacing
     * collapsed), not on the string itself: "Vidéoprojecteur", "vidéo-projecteur" and
     * "Videoprojecteur" are one support typed by three teachers, and printing the three of them
     * side by side is what made the summary read as noise. The first spelling met is the one kept,
     * accents and all - the fold decides what is the same, never what is displayed.
     *
     * Sorted alphabetically on that same fold, because the result is an inventory read by scanning
     * rather than a chronology, and capped, because this is a summary: past a couple of dozen
     * entries the list stops being read, and the per-séance detail below it is the exhaustive
     * source anyway.
     *
     * @param list<string> $means
     *
     * @return list<string>
     */
    private function distinctMeans(array $means): array
    {
        $seen = [];
        foreach ($means as $entry) {
            $key = $this->meansKey($entry);
            if ('' !== $key && !isset($seen[$key])) {
                $seen[$key] = $entry;
            }
        }

        ksort($seen, \SORT_STRING);

        return \array_slice(array_values($seen), 0, 24);
    }

    /**
     * The comparison form of a support's name: lowercase, unaccented, and stripped of everything
     * that is not a letter or a digit.
     *
     * Accents go through an NFD decomposition (which splits "é" into "e" + a combining acute) and
     * the removal of the combining marks it produces, rather than through an iconv//TRANSLIT, whose
     * output depends on the process locale and would silently differ between the dev container and
     * the production one.
     */
    private function meansKey(string $entry): string
    {
        $decomposed = \Normalizer::normalize($entry, \Normalizer::FORM_D);

        return (string) preg_replace(
            '/[^a-z0-9]+/u',
            '',
            mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', false === $decomposed ? $entry : $decomposed)),
        );
    }

    /**
     * One printed line per séance. An empty $placements list is a séance the progression carries but
     * has not put on a créneau yet - it still belongs in the document, as the plan, and the summary
     * counts it as unplaced rather than hiding it (its `learnerMinutes` is then 0: nothing has been
     * delivered, and `plannedMinutes` is what the row prints instead).
     *
     * @param list<ProgressionSeancePlacement> $placements validated ones - what the figures count
     * @param list<ProgressionSeancePlacement> $proposals  the auto-planner's, printed as such
     *
     * @return SeanceRow
     */
    private function seanceRow(ProgressionSeance $seance, array $placements, array $proposals, ?int $sequenceInstanceId): array
    {
        $instance = $seance->getSeanceInstance();
        $deliveries = $this->deliveryRows($placements);

        return [
            'title' => $seance->getTitle(),
            'deliveries' => $deliveries,
            'proposals' => $this->deliveryRows($proposals),
            'redeliveries' => $this->redeliveries($deliveries),
            'plannedMinutes' => $seance->getPlannedMinutesOrZero(),
            'learnerMinutes' => $this->learnerMinutes($deliveries),
            'nature' => $seance->getEvaluationNature(),
            'objectifs' => $instance?->getObjectifs(),
            'materials' => $instance?->getMaterials(),
            'phases' => $this->phaseRows($seance),
            'sequenceInstanceId' => $sequenceInstanceId,
            'seanceInstanceId' => $instance?->getId(),
        ];
    }

    /**
     * The printable form of a set of placements, chronological - so that "the first delivery" means
     * the one that happened first, and the ones after it read as re-deliveries of it.
     *
     * @param list<ProgressionSeancePlacement> $placements
     *
     * @return list<DeliveryRow>
     */
    private function deliveryRows(array $placements): array
    {
        $rows = [];

        foreach ($placements as $placement) {
            $session = $placement->getLessonSession();
            $option = $placement->getOption();

            $rows[] = [
                'date' => $session?->getDay(),
                'start' => $session?->getStartHour()?->format('H:i'),
                'end' => $session?->getEndHour()?->format('H:i'),
                'room' => $session?->getClassRoom()?->getName(),
                'group' => $option?->getShortName(),
                // Keyed on the id, not on the printed short name: two Options may well share a
                // label, and the volume below is decided by this key.
                'groupKey' => null === $option ? '' : (string) $option->getId(),
                'minutes' => $placement->getDurationMinutes(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['date']?->format('Y-m-d') ?? '9999', $a['start'] ?? '99:99'] <=> [$b['date']?->format('Y-m-d') ?? '9999', $b['start'] ?? '99:99']);

        return $rows;
    }

    /**
     * What ONE apprenant receives from a séance, in minutes.
     *
     * A delivery with no groupe is given to the whole class, so everybody gets it; a delivery
     * scoped to a groupe is received by that groupe alone. An apprenant therefore receives every
     * whole-class delivery, plus the ones of their own groupe - so the séance's learner volume is
     * the whole-class total plus the heaviest single groupe.
     *
     * That "heaviest" is what makes the two cases come out right with one rule: a séance dédoublée
     * (55 min to G1, 55 min to G2) counts 55, while a séance spread over two créneaux for the same
     * groupe (55 + 30) counts 85 - which is indeed what its apprenants sat through. Summing the
     * placements instead counts the first case twice, and that was the 44 h 05 against 28 h.
     *
     * @param list<DeliveryRow> $deliveries
     */
    private function learnerMinutes(array $deliveries): int
    {
        $wholeClass = 0;
        $perGroup = [];

        foreach ($deliveries as $delivery) {
            if ('' === $delivery['groupKey']) {
                $wholeClass += $delivery['minutes'];
                continue;
            }

            $perGroup[$delivery['groupKey']] = ($perGroup[$delivery['groupKey']] ?? 0) + $delivery['minutes'];
        }

        return $wholeClass + ([] === $perGroup ? 0 : max($perGroup));
    }

    /**
     * The deliveries that re-give the séance to another groupe - everything scoped to a groupe other
     * than the first delivery's. These are the ones the détail names ("redispensée le … au groupe
     * …"), and the reason a learner volume is smaller than the face-à-face it took.
     *
     * A second créneau for the SAME groupe is not one of these: nothing was re-given, the séance
     * simply spans two slots, and its apprenants received both.
     *
     * @param list<DeliveryRow> $deliveries
     *
     * @return list<DeliveryRow>
     */
    private function redeliveries(array $deliveries): array
    {
        $reference = null;
        foreach ($deliveries as $delivery) {
            if ('' !== $delivery['groupKey']) {
                $reference = $delivery['groupKey'];
                break;
            }
        }

        if (null === $reference) {
            return [];
        }

        return array_values(array_filter(
            $deliveries,
            static fn (array $delivery): bool => '' !== $delivery['groupKey'] && $delivery['groupKey'] !== $reference,
        ));
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
