<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\PortfolioAchievement;
use App\Entity\ReferentialCompetency;

/**
 * The synthesis table as App\Service\Portfolio\PortfolioSynthesis reads it - the one shape the
 * screen, the class view, the .xlsx writer, the PDF and a deposit's snapshot are all drawn from.
 *
 * A cell is `retained` (a retained claim on a validated réalisation - the only thing the official
 * table ever ticks), `pending` (a claim still waiting - on screen only) or null.
 */
final readonly class SynthesisTable
{
    public const string RETAINED = 'retained';
    public const string PENDING = 'pending';

    /**
     * @param list<ReferentialCompetency>                                                                                                                          $columns
     * @param array<int, list<array{achievement: PortfolioAchievement, title: string, documents: string, period: string, cells: array<int, ?string>, validated: bool}>> $sections  keyed 1, 2, 3
     * @param array<int, int>                                                                                                                                      $coverage  competency id => validated réalisations retaining it
     * @param list<array{label: string, checked: bool}>                                                                                                            $options
     */
    public function __construct(
        public string $studentName,
        public ?string $candidateNumber,
        public ?string $trainingCentre,
        public array $options,
        public ?string $portfolioUrl,
        public ?int $session,
        public array $columns,
        public array $sections,
        public array $coverage,
        public bool $includesPending,
    ) {
    }

    /** @return list<ReferentialCompetency> the columns no validated réalisation retains yet */
    public function missing(): array
    {
        return array_values(array_filter($this->columns, fn (ReferentialCompetency $column): bool => 0 === ($this->coverage[(int) $column->getId()] ?? 0)));
    }

    public function coveredCount(): int
    {
        return \count($this->columns) - \count($this->missing());
    }

    /** Is the whole bloc 1 mobilised? The circulaire requires it of the réalisations as a whole. */
    public function isComplete(): bool
    {
        return [] !== $this->columns && [] === $this->missing();
    }

    public function isEmpty(): bool
    {
        foreach ($this->sections as $rows) {
            if ([] !== $rows) {
                return false;
            }
        }

        return true;
    }

    /**
     * A plain array for a deposit's snapshot - labels and marks only, no entity.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        $sections = [];
        foreach ($this->sections as $number => $rows) {
            $sections[$number] = array_map(static fn (array $row): array => [
                'achievementId' => $row['achievement']->getId(),
                'revision' => $row['achievement']->getRevision(),
                'title' => $row['title'],
                'documents' => $row['documents'],
                'period' => $row['period'],
                'cells' => $row['cells'],
            ], $rows);
        }

        return [
            'studentName' => $this->studentName,
            'candidateNumber' => $this->candidateNumber,
            'trainingCentre' => $this->trainingCentre,
            'options' => $this->options,
            'portfolioUrl' => $this->portfolioUrl,
            'session' => $this->session,
            'columns' => array_map(static fn (ReferentialCompetency $column): array => ['id' => $column->getId(), 'label' => $column->getLabel()], $this->columns),
            'sections' => $sections,
            'coverage' => $this->coverage,
        ];
    }
}
