<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Evaluation;
use App\Entity\Progression;
use App\Entity\SchoolYear;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EvaluationNature;
use App\Repository\EvaluationRepository;
use App\Repository\ProgressionRepository;

/**
 * Builds the read-only calendars of screens 4a (ten months in horizontal columns) and 4b (one
 * month, weeks in columns, days inside).
 *
 * Both are pure projections of what the placement side already decided: a card is either a séance
 * that has a placement on a real créneau, or a typed evaluation. Nothing here writes, and nothing
 * here re-derives dates - if a séance shows up in November it is because its créneau is in
 * November.
 */
class ProgressionCalendarBuilder
{
    // The design's fixed ten-month band, in school order.
    private const array MONTH_ORDER = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6];

    public function __construct(
        private readonly ProgressionRepository $progressionRepository,
        private readonly EvaluationRepository $evaluationRepository,
    ) {
    }

    /**
     * Screen 4a. One entry per month (September → June), each holding one block per class, each
     * block holding matière+séquence cards and coloured evaluation cards.
     *
     * @param list<int>            $cohortIds  empty = every class
     * @param array{topicId?: int|null, nature?: EvaluationNature|null, withEvaluation?: bool} $filters
     *
     * @return list<array{key: string, year: int, month: int, isCurrent: bool, classes: list<array{label: string, cards: list<array<string, mixed>>}>}>
     */
    public function annual(User $teacher, SchoolYear $schoolYear, array $cohortIds, array $filters, \DateTimeImmutable $today): array
    {
        $progressions = $this->progressionRepository->findForTeacherWithPlacements($teacher, $schoolYear);
        $progressions = $this->applyScopeFilters($progressions, $cohortIds, $filters['topicId'] ?? null);

        $cardsByMonth = [];

        foreach ($progressions as $progression) {
            foreach ($this->sequenceCards($progression) as $card) {
                $cardsByMonth[$card['monthKey']][] = $card;
            }
        }

        foreach ($this->evaluationCards($progressions, $schoolYear) as $card) {
            $cardsByMonth[$card['monthKey']][] = $card;
        }

        $months = [];
        foreach ($this->monthsOf($schoolYear) as [$year, $month]) {
            $key = sprintf('%04d-%02d', $year, $month);
            $cards = $this->filterByEvaluation($cardsByMonth[$key] ?? [], $filters);

            $months[] = [
                'key' => $key,
                'year' => $year,
                'month' => $month,
                'isCurrent' => (int) $today->format('Y') === $year && (int) $today->format('n') === $month,
                'classes' => $this->groupByClass($this->collapseToSequences($cards)),
            ];
        }

        return $months;
    }

    /**
     * 4a shows one card per séquence per class per month ("cartes matière + séquence, pas de
     * sous-titre") - not one per séance, which is 4b's job. Séance cards of the same séquence
     * therefore collapse into the first of them; evaluation cards are always kept as-is, since
     * each is its own event.
     *
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    private function collapseToSequences(array $cards): array
    {
        $collapsed = [];

        foreach ($cards as $card) {
            if ('seance' !== $card['type']) {
                $collapsed[] = $card;
                continue;
            }

            // Cards come in two variants (séance and evaluation) that share most but not all of
            // their keys, so the shape stays loose across this builder. sequenceId is ?int in both:
            // null means "not attached to a séquence", which groups those cards under one key.
            $sequenceId = $card['sequenceId'];
            $key = sprintf('%d-%d-%s', $card['cohortId'], $card['topicId'], \is_int($sequenceId) ? (string) $sequenceId : '');
            if (isset($collapsed[$key])) {
                // A flag raised on any séance of the séquence has to survive the collapse - it is
                // the whole point of §4.3/§4.7's "signalée sur la vue de progression".
                $collapsed[$key]['tooShort'] = $collapsed[$key]['tooShort'] || $card['tooShort'];
                $collapsed[$key]['needsReassociation'] = $collapsed[$key]['needsReassociation'] || $card['needsReassociation'];
                continue;
            }

            $card['title'] = $card['sequenceTitle'];
            // The card now stands for the whole séquence, so the first séance's identity must not
            // travel with it - 4a links to the séquence, never to whichever séance happened to be
            // first. (That the nature DID travel this way is the bug behind 4a's evaluation
            // colouring - see the partial.)
            $card['seanceInstanceId'] = null;
            $collapsed[$key] = $card;
        }

        return array_values($collapsed);
    }

    /**
     * Screen 4b. Weeks in columns (ISO week number), days grouped inside, one card per séance with
     * its real hours.
     *
     * @param list<int>            $cohortIds
     * @param array{topicId?: int|null, nature?: EvaluationNature|null, withEvaluation?: bool} $filters
     *
     * @return list<array{week: int, start: \DateTimeImmutable, end: \DateTimeImmutable, days: list<array{day: \DateTimeImmutable, cards: list<array<string, mixed>>}>}>
     */
    public function month(User $teacher, SchoolYear $schoolYear, \DateTimeImmutable $month, array $cohortIds, array $filters): array
    {
        $progressions = $this->progressionRepository->findForTeacherWithPlacements($teacher, $schoolYear);
        $progressions = $this->applyScopeFilters($progressions, $cohortIds, $filters['topicId'] ?? null);

        $monthKey = $month->format('Y-m');
        $cards = [];

        foreach ($progressions as $progression) {
            foreach ($this->sequenceCards($progression) as $card) {
                if ($card['monthKey'] === $monthKey) {
                    $cards[] = $card;
                }
            }
        }

        foreach ($this->evaluationCards($progressions, $schoolYear) as $card) {
            if ($card['monthKey'] === $monthKey) {
                $cards[] = $card;
            }
        }

        $cards = $this->filterByEvaluation($cards, $filters);

        $byWeekAndDay = [];
        foreach ($cards as $card) {
            /** @var \DateTimeImmutable $day */
            $day = $card['day'];
            $byWeekAndDay[(int) $day->format('W')][$day->format('Y-m-d')][] = $card;
        }

        ksort($byWeekAndDay);

        $weeks = [];
        foreach ($byWeekAndDay as $week => $days) {
            ksort($days);

            $dayEntries = [];
            foreach ($days as $iso => $dayCards) {
                usort($dayCards, static fn (array $a, array $b): int => ($a['start'] ?? '') <=> ($b['start'] ?? ''));
                $dayEntries[] = ['day' => new \DateTimeImmutable($iso), 'cards' => $dayCards];
            }

            // The header reads "02 – 06 nov.", i.e. the whole teaching week, not the span of the
            // days that happen to carry a card (a lone Tuesday would otherwise print "03 – 03").
            $monday = $dayEntries[0]['day']->modify('monday this week');

            $weeks[] = [
                'week' => $week,
                'start' => $monday,
                'end' => $monday->modify('+4 days'),
                'days' => $dayEntries,
            ];
        }

        return $weeks;
    }

    /**
     * The Classes / Matière dropdown contents, built from what this teacher actually has a
     * progression for - never from the whole structure tree.
     *
     * @param list<Progression> $progressions
     *
     * @return array{cohorts: list<array{id: int, label: string}>, topics: list<array{id: int, label: string}>}
     */
    public function filterOptions(array $progressions): array
    {
        $cohorts = [];
        $topics = [];

        foreach ($progressions as $progression) {
            $cohort = $progression->getProgram()?->getCohort();
            $topic = $progression->getTopic();

            if (null !== $cohort) {
                $cohorts[(int) $cohort->getId()] = ['id' => (int) $cohort->getId(), 'label' => $cohort->getName()];
            }
            if (null !== $topic) {
                $topics[(int) $topic->getId()] = ['id' => (int) $topic->getId(), 'label' => $topic->getName()];
            }
        }

        usort($cohorts, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);
        usort($topics, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        return ['cohorts' => $cohorts, 'topics' => $topics];
    }

    /** @return list<Progression> */
    public function progressionsFor(User $teacher, SchoolYear $schoolYear): array
    {
        return $this->progressionRepository->findForTeacherWithPlacements($teacher, $schoolYear);
    }

    /**
     * One card per placed séance. A séance split over two créneaux legitimately produces two
     * cards - they are two distinct moments in the year, which is exactly what the calendar is
     * for.
     *
     * @return list<array<string, mixed>>
     */
    private function sequenceCards(Progression $progression): array
    {
        $cards = [];
        $program = $progression->getProgram();
        $cohort = $program?->getCohort();
        $topic = $progression->getTopic();

        foreach ($progression->getSequences() as $sequence) {
            $sequenceInstance = $sequence->getSequenceInstance();

            foreach ($sequence->getActiveSeances() as $seance) {
                foreach ($seance->getActivePlacements() as $placement) {
                    $session = $placement->getLessonSession();
                    $day = $session?->getDay();
                    if (null === $session || null === $day) {
                        continue;
                    }

                    $cards[] = [
                        'type' => 'seance',
                        'monthKey' => $day->format('Y-m'),
                        'day' => $day,
                        'start' => $session->getStartHour()?->format('H:i'),
                        'end' => $session->getEndHour()?->format('H:i'),
                        'cohortId' => (int) ($cohort?->getId() ?? 0),
                        'cohortLabel' => $cohort?->getName() ?? '—',
                        'cohortColor' => $cohort?->getColor(),
                        'topicId' => (int) ($topic?->getId() ?? 0),
                        'topicLabel' => $topic?->getName() ?? '—',
                        // The group this particular card serves, for a séance reproduced once per
                        // group (§4.9). One card per placement, so each one names its own Option -
                        // which is exactly what makes the two otherwise identical cards of a
                        // duplicated séance tellable apart on the month view. Null for a séance
                        // taught to the whole class, and only ever rendered on 4b: 4a collapses
                        // cards to séquences, where a group has nothing to say.
                        'groupLabel' => $placement->getOption()?->getShortName(),
                        'groupColor' => $placement->getOption()?->getColor(),
                        // 4b names the card after the séance (it is a single lesson there), 4a
                        // after the séquence - see collapseToSequences().
                        'title' => '' !== $seance->getTitle() ? $seance->getTitle() : $sequence->getTitle(),
                        'sequenceTitle' => $sequence->getTitle(),
                        // A séance flagged as carrying an evaluation IS the evaluation card for
                        // that date - no App\Entity\Evaluation row needed. That is the whole point
                        // of the flag: the séquence says where its evaluations fall, and placing it
                        // puts them on real dates. A nature here also makes the card pass the
                        // "Évaluations" filter and take its colour, exactly like a posed one.
                        'nature' => $seance->getEvaluationNature(),
                        'progressionId' => $progression->getId(),
                        'sequenceId' => $sequence->getId(),
                        // What it takes to reach the fiche séquence
                        // (app_program_sequences_show), which 4a links its séquence names to and 4b
                        // its séances. That screen is keyed on the SequenceInstance - the frozen
                        // per-class copy - not on the ProgressionSequence that plans it, so the two
                        // ids are not interchangeable.
                        //
                        // ...and 4b at the séance's own sheet, app_program_seances_show, which needs
                        // the SeanceInstance - the class's copy. A séance added straight on screen
                        // 2a has none, and its card falls back to the séquence sheet.
                        'programId' => $program?->getId(),
                        'sequenceInstanceId' => $sequenceInstance?->getId(),
                        'seanceInstanceId' => $seance->getSeanceInstance()?->getId(),
                        // The fiche séquence is behind ProgramFeatureGuardTrait, so on a Program
                        // with the timetable feature off it answers 404. Carried here rather than
                        // asked in Twig so the card can simply not be a link, instead of being one
                        // that breaks.
                        'sheetReachable' => true === $program?->isTimetableManagementEnabled(),
                        'tooShort' => $seance->isTooShort(),
                        'needsReassociation' => $seance->needsReassociation(),
                    ];
                }
            }
        }

        return $cards;
    }

    /**
     * @param list<Progression> $progressions
     *
     * @return list<array<string, mixed>>
     */
    private function evaluationCards(array $progressions, SchoolYear $schoolYear): array
    {
        $topics = [];
        $progressionByTopicId = [];

        foreach ($progressions as $progression) {
            $topic = $progression->getTopic();
            if (null !== $topic) {
                $topics[] = $topic;
                $progressionByTopicId[(int) $topic->getId()] = $progression;
            }
        }

        $from = $schoolYear->getStartDate() ?? new \DateTimeImmutable('-1 year');
        $to = $schoolYear->getEndDate() ?? new \DateTimeImmutable('+1 year');

        return array_map(
            function (Evaluation $evaluation) use ($progressionByTopicId): array {
                /** @var Topic $topic */
                $topic = $evaluation->getTopic();
                $progression = $progressionByTopicId[(int) $topic->getId()] ?? null;
                $cohort = $progression?->getProgram()?->getCohort();
                /** @var \DateTimeImmutable $date */
                $date = $evaluation->getDate();
                $session = $evaluation->getLessonSession();

                return [
                    'type' => 'evaluation',
                    'monthKey' => $date->format('Y-m'),
                    'day' => $date->setTime(0, 0),
                    'start' => $session?->getStartHour()?->format('H:i') ?? $date->format('H:i'),
                    'end' => $session?->getEndHour()?->format('H:i'),
                    'cohortId' => (int) ($cohort?->getId() ?? 0),
                    'cohortLabel' => $cohort?->getName() ?? '—',
                    'cohortColor' => $cohort?->getColor(),
                    'topicId' => (int) $topic->getId(),
                    'topicLabel' => $topic->getName(),
                    // Both card shapes have to carry the same keys - twig runs with
                    // strict_variables, so a key only one of them defines is a hard error in the
                    // shared partial. A posed evaluation belongs to the class, not to a group.
                    'groupLabel' => null,
                    'groupColor' => null,
                    'title' => $evaluation->getName(),
                    'sequenceTitle' => $evaluation->getName(),
                    'nature' => $evaluation->getNature(),
                    'progressionId' => $progression?->getId(),
                    'sequenceId' => $evaluation->getProgressionSequence()?->getId(),
                    // Never a link: an evaluation is its own event, not a séquence or a séance, and
                    // the fiche séquence has nothing to say about it. The keys are still declared
                    // because twig runs with strict_variables and the two card shapes share one
                    // partial - the same reason groupLabel/groupColor are declared null above.
                    'programId' => null,
                    'sequenceInstanceId' => null,
                    'seanceInstanceId' => null,
                    'sheetReachable' => false,
                    'tooShort' => false,
                    'needsReassociation' => false,
                ];
            },
            $this->evaluationRepository->findTypedForTopicsBetween($topics, $from, $to),
        );
    }

    /**
     * The design's "Évaluations" dropdown: Toutes les cartes / Avec une évaluation / D / F / S.
     *
     * What makes a card an evaluation is its NATURE, not its type: a posed App\Entity\Evaluation
     * always has one, and a séance flagged "contient une évaluation" now carries one too. Keying on
     * the type instead would have hidden exactly the séances this feature exists to surface.
     *
     * @param list<array<string, mixed>> $cards
     * @param array{nature?: EvaluationNature|null, withEvaluation?: bool} $filters
     *
     * @return list<array<string, mixed>>
     */
    private function filterByEvaluation(array $cards, array $filters): array
    {
        $nature = $filters['nature'] ?? null;
        $withEvaluation = $filters['withEvaluation'] ?? false;

        if (null === $nature && !$withEvaluation) {
            return $cards;
        }

        return array_values(array_filter($cards, static function (array $card) use ($nature): bool {
            if (null === $card['nature']) {
                return false;
            }

            return null === $nature || $card['nature'] === $nature;
        }));
    }

    /**
     * @param list<Progression> $progressions
     * @param list<int>         $cohortIds
     *
     * @return list<Progression>
     */
    private function applyScopeFilters(array $progressions, array $cohortIds, ?int $topicId): array
    {
        return array_values(array_filter($progressions, static function (Progression $progression) use ($cohortIds, $topicId): bool {
            $cohortId = (int) ($progression->getProgram()?->getCohort()?->getId() ?? 0);
            if ([] !== $cohortIds && !\in_array($cohortId, $cohortIds, true)) {
                return false;
            }

            return null === $topicId || (int) ($progression->getTopic()?->getId() ?? 0) === $topicId;
        }));
    }

    /**
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array{label: string, color: string|null, cards: list<array<string, mixed>>}>
     */
    private function groupByClass(array $cards): array
    {
        $blocks = [];
        foreach ($cards as $card) {
            $blocks[$card['cohortLabel']]['label'] = $card['cohortLabel'];
            $blocks[$card['cohortLabel']]['color'] = $card['cohortColor'];
            $blocks[$card['cohortLabel']]['cards'][] = $card;
        }

        ksort($blocks);

        foreach ($blocks as $label => $block) {
            usort($block['cards'], static fn (array $a, array $b): int => [$a['day'], $a['start'] ?? ''] <=> [$b['day'], $b['start'] ?? '']);
            $blocks[$label] = $block;
        }

        return array_values($blocks);
    }

    /** @return list<array{0: int, 1: int}> */
    private function monthsOf(SchoolYear $schoolYear): array
    {
        $startYear = (int) ($schoolYear->getStartDate()?->format('Y') ?? date('Y'));
        $months = [];

        foreach (self::MONTH_ORDER as $month) {
            $months[] = [$month >= 9 ? $startYear : $startYear + 1, $month];
        }

        return $months;
    }
}
