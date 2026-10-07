<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\EcfActivity;
use App\Entity\EcfBooklet;
use App\Entity\EcfEvaluationRow;
use App\Entity\EcfVisa;
use App\Enum\EcfPart;
use App\Enum\EcfResult;
use App\Enum\EcfVisaSlot;
use App\Repository\InternshipFormationCenterRepository;
use App\Service\FileUploadService;

/**
 * Everything templates/ufa/ecf/print.html.twig prints, computed once: the template only lays it
 * out, page for page like the ministry's model (design/validated/ecf-booklet.md, R10).
 *
 * Activities are printed in the booklet's order; a sheet whose group the formation no longer
 * offers is printed only if a visa signed it. A signed sheet prints the labels it was signed with.
 *
 * @phpstan-type PrintRow array{number: int, paragraphs: list<string>, date: \DateTimeImmutable|null, columns: array{0: string, 1: string, 2: string}, ticked: list<int>}
 * @phpstan-type PrintVisa array{name: string, date: \DateTimeImmutable, signedAt: \DateTimeImmutable}|null
 * @phpstan-type PrintActivity array{anchor: string, number: int, label: string, competences: list<string>, rows: list<PrintRow>, result: EcfResult|null, attention: list<string>, reassess: list<string>, visas: list<PrintVisa>, complementaryRows: list<PrintRow>, complementaryResult: EcfResult|null, observations: list<string>, complementaryVisas: list<PrintVisa>, mastered: bool|null}
 */
class EcfPrintBuilder
{
    public const string TYPE_OF_DOCUMENT = 'Livret d’évaluations passées en cours de formation';

    public function __construct(
        private readonly EcfMastery $mastery,
        private readonly InternshipFormationCenterRepository $formationCenterRepository,
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    /**
     * @return array{activities: list<PrintActivity>, synthesis: array{rows: list<array{number: int, label: string, competences: list<string>, mastered: bool|null}>, observations: list<string>, evaluators: list<PrintVisa>, representative: PrintVisa, remittedOn: \DateTimeImmutable|null}, logo: string|null, footer: array{sigle: string, code: string, millesime: string, journal: string, updated: string}}
     */
    public function build(EcfOverview $overview): array
    {
        $booklet = $overview->booklet;
        $activities = [];
        $number = 0;

        foreach ($overview->rows as $row) {
            ++$number;
            $activities[] = $this->activity($booklet, $row['activity'], $number, $row['type']->label, $row['type']->competences);
        }
        foreach ($overview->orphans as $orphan) {
            if ($this->mastery->isSigned($booklet, $orphan, EcfPart::Main) || $this->mastery->isSigned($booklet, $orphan, EcfPart::Complementary)) {
                ++$number;
                $activities[] = $this->activity($booklet, $orphan, $number, $orphan->getGroupCode(), []);
            }
        }
        $synthesisRows = array_map(static fn (array $activity): array => [
            'number' => $activity['number'],
            'label' => $activity['label'],
            'competences' => $activity['competences'],
            'mastered' => $activity['mastered'],
        ], $activities);

        $synthesisVisas = $this->bySlot(EcfMastery::visasOf($booklet, null, EcfPart::Synthesis));

        return [
            'activities' => $activities,
            'synthesis' => [
                'rows' => $synthesisRows,
                'observations' => self::lines($booklet->getSynthesisObservations()),
                'evaluators' => [$synthesisVisas[EcfVisaSlot::Evaluator1->value] ?? null, $synthesisVisas[EcfVisaSlot::Evaluator2->value] ?? null],
                'representative' => $synthesisVisas[EcfVisaSlot::Representative->value] ?? null,
                'remittedOn' => $booklet->getRemittedOn(),
            ],
            'logo' => $this->logo(),
            'footer' => [
                'sigle' => $overview->title->sigle,
                'code' => $booklet->getTitleCode(),
                'millesime' => $booklet->getMillesime(),
                'journal' => $overview->title->journalDate?->format('d/m/Y') ?? '',
                'updated' => $overview->title->modelUpdatedDate?->format('d/m/Y') ?? '',
            ],
        ];
    }

    /**
     * The anchors the online reader's table of contents points at, in printed order.
     *
     * @param list<PrintActivity> $activities
     *
     * @return list<array{anchor: string, level: int, number: string|null, labelKey: string|null, label: string|null}>
     */
    public static function outline(array $activities): array
    {
        $outline = [
            ['anchor' => 'ecf-cover', 'level' => 1, 'number' => null, 'labelKey' => 'ecfCoverTitle', 'label' => null],
            ['anchor' => 'ecf-presentation', 'level' => 1, 'number' => null, 'labelKey' => 'ecfPresentationTitle', 'label' => null],
        ];
        foreach ($activities as $activity) {
            $outline[] = ['anchor' => $activity['anchor'], 'level' => 1, 'number' => null, 'labelKey' => null, 'label' => 'Activité-type '.$activity['number']];
            $outline[] = ['anchor' => $activity['anchor'].'-complementary', 'level' => 2, 'number' => null, 'labelKey' => 'ecfComplementaryTitle', 'label' => null];
        }
        $outline[] = ['anchor' => 'ecf-synthesis', 'level' => 1, 'number' => null, 'labelKey' => 'ecfSynthesisLabel', 'label' => null];

        return $outline;
    }

    /**
     * @param list<string> $competences
     *
     * @return PrintActivity
     */
    private function activity(EcfBooklet $booklet, ?EcfActivity $activity, int $number, string $label, array $competences): array
    {
        $competences = $activity?->getFrozenCompetences() ?? $competences;
        $mainVisas = $this->bySlot(EcfMastery::visasOf($booklet, $activity, EcfPart::Main));
        $complementaryVisas = $this->bySlot(EcfMastery::visasOf($booklet, $activity, EcfPart::Complementary));
        $notSatisfied = null !== $activity && EcfResult::NotSatisfied === $activity->getResult();

        $reassess = [];
        $attention = [];
        if (null !== $activity && $notSatisfied) {
            $attention = self::lines($activity->getAttentionPoints());
            foreach ($activity->getReassessCompetences() as $n) {
                $reassess[] = $n.'. '.($competences[$n - 1] ?? '');
            }
            $reassess = [...$reassess, ...self::lines($activity->getReassessNote())];
        }

        return [
            'anchor' => 'ecf-at-'.$number,
            'number' => $number,
            'label' => $activity?->getFrozenLabel() ?? $label,
            'competences' => $competences,
            'rows' => array_map(self::row(...), $activity?->rowsOf(EcfPart::Main) ?? []),
            'result' => $activity?->getResult(),
            'attention' => $attention,
            'reassess' => $reassess,
            'visas' => [$mainVisas[EcfVisaSlot::Evaluator1->value] ?? null, $mainVisas[EcfVisaSlot::Evaluator2->value] ?? null],
            'complementaryRows' => array_map(self::row(...), $activity?->rowsOf(EcfPart::Complementary) ?? []),
            'complementaryResult' => $activity?->getComplementaryResult(),
            'observations' => self::lines($activity?->getComplementaryObservations()),
            'complementaryVisas' => [$complementaryVisas[EcfVisaSlot::Evaluator1->value] ?? null, $complementaryVisas[EcfVisaSlot::Evaluator2->value] ?? null],
            'mastered' => $this->mastery->isMastered($booklet, $activity),
        ];
    }

    /**
     * The main sheet's « Compétences évaluées » has three cells: one number in each, the third
     * taking the rest when a line evaluates more than three.
     *
     * @return PrintRow
     */
    private static function row(EcfEvaluationRow $row): array
    {
        $numbers = array_map('strval', $row->getCompetences());
        $columns = [$numbers[0] ?? '', $numbers[1] ?? '', implode(', ', \array_slice($numbers, 2))];

        return [
            'number' => $row->getPosition(),
            'paragraphs' => $row->paragraphs(),
            'date' => $row->getEvaluatedOn(),
            'columns' => $columns,
            'ticked' => $row->getCompetences(),
        ];
    }

    /**
     * @param list<EcfVisa> $visas
     *
     * @return array<string, PrintVisa>
     */
    private function bySlot(array $visas): array
    {
        $bySlot = [];
        foreach ($visas as $visa) {
            $bySlot[$visa->getSlot()->value] = ['name' => $visa->getSignerName(), 'date' => $visa->getEvaluatedOn(), 'signedAt' => $visa->getSignedAt()];
        }

        return $bySlot;
    }

    /** @return list<string> */
    private static function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $text) ?: []), static fn (string $line): bool => '' !== $line));
    }

    // The ministry's bloc-marque, uploaded in UFA > Configuration and inlined as a data URI so
    // Gotenberg fetches nothing. Null prints the cover with the place left empty.
    private function logo(): ?string
    {
        $key = $this->formationCenterRepository->findSingleton()?->getEcfMinistryLogoKey();
        if (null === $key) {
            return null;
        }

        try {
            return 'data:'.$this->fileUploadService->mimeType($key).';base64,'.base64_encode($this->fileUploadService->read($key));
        } catch (\Throwable) {
            return null;
        }
    }
}
