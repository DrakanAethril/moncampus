<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\Portfolio;
use App\Entity\PortfolioAchievement;
use App\Entity\PortfolioEvidence;
use App\Entity\ReferentialCompetency;
use App\Enum\PortfolioClaimState;
use App\Enum\PortfolioEvidenceKind;
use App\Enum\PortfolioState;
use App\Enum\ReferentialBlockRole;
use App\Repository\InternshipFormationCenterRepository;

/**
 * **The synthesis table is computed, nobody ticks it** (§3, decision 4; R5).
 *
 * The single reading of a portfolio into the annexe VI-1 shape: the screen, the class view, the
 * .xlsx, the PDF and a deposit's snapshot all come from here. Its rules:
 *
 * - the columns are the competencies of the référentiel's synthesis block, in their order;
 * - each réalisation goes to the part(s) PortfolioSectionResolver gives it (R4);
 * - a cell is ticked only by a **retained** claim on a **validated** réalisation;
 * - with `includePending`, the réalisations waiting for a decision show too, their claims as
 *   hollow marks - the screen's view; an export never passes it;
 * - drafts never show: nothing has been asked of anybody yet.
 */
class PortfolioSynthesis
{
    public function __construct(
        private readonly PortfolioContext $context,
        private readonly PortfolioSectionResolver $sections,
        private readonly InternshipFormationCenterRepository $formationCenters,
    ) {
    }

    public function build(Portfolio $portfolio, bool $includePending = false): SynthesisTable
    {
        $student = $portfolio->getStudent();
        $referential = $portfolio->getReferential();
        $program = null === $student ? null : $this->context->currentProgram($student, $referential);
        $option = $this->context->optionOfPortfolio($portfolio);

        $options = [];
        if (null !== $referential) {
            foreach ($referential->getBlocks() as $block) {
                if (ReferentialBlockRole::Showcase !== $block->getRole()) {
                    continue;
                }
                foreach ($block->getOptions() as $blockOption) {
                    $options[(int) $blockOption->getId()] = ['label' => $blockOption->getShortName(), 'checked' => $blockOption->getId() === $option?->getId()];
                }
            }
        }

        return self::compose(
            $portfolio,
            $includePending,
            $this->context->yearSpans($portfolio),
            $this->sections,
            null === $student ? '' : self::officialName($student->getLastname(), $student->getFirstname(), $student->getUsername()),
            $this->formationCenters->findSingleton()?->getCompanyName(),
            array_values($options),
            $program?->getPortfolioExamSession(),
        );
    }

    /**
     * The rule itself, on what it needs and nothing else - tested without a database.
     *
     * @param list<array{from: \DateTimeImmutable, until: \DateTimeImmutable, cursusYear: int}> $years
     * @param list<array{label: string, checked: bool}>                                        $options
     */
    public static function compose(Portfolio $portfolio, bool $includePending, array $years, PortfolioSectionResolver $resolver, string $studentName, ?string $trainingCentre, array $options, ?int $session): SynthesisTable
    {
        $columns = [];
        $block = $portfolio->getReferential()?->getSynthesisBlock();
        if (null !== $block) {
            $columns = array_values($block->getCompetencies()->toArray());
        }

        $columnIds = array_map(static fn (ReferentialCompetency $column): int => (int) $column->getId(), $columns);
        $coverage = array_fill_keys($columnIds, 0);
        $sections = [1 => [], 2 => [], 3 => []];

        foreach ($portfolio->getAchievements() as $achievement) {
            $validated = PortfolioState::Validated === $achievement->getState();

            if (!$validated && (!$includePending || PortfolioState::Draft === $achievement->getState())) {
                continue;
            }

            $cells = array_fill_keys($columnIds, null);
            foreach ($achievement->getClaims() as $claim) {
                $id = (int) $claim->getCompetency()?->getId();
                if (!\array_key_exists($id, $cells)) {
                    continue;
                }

                if ($validated && PortfolioClaimState::Retained === $claim->getState()) {
                    $cells[$id] = SynthesisTable::RETAINED;
                    ++$coverage[$id];
                } elseif (!$validated && PortfolioClaimState::Claimed === $claim->getState()) {
                    $cells[$id] = SynthesisTable::PENDING;
                }
            }

            $row = [
                'achievement' => $achievement,
                'title' => $achievement->getTitle(),
                'documents' => self::documents($achievement),
                'period' => self::period($achievement),
                'cells' => $cells,
                'validated' => $validated,
            ];

            foreach ($resolver->sections($achievement->getSetting(), $achievement->getStartsOn(), $achievement->getEndsOn(), $years) as $section) {
                $sections[$section][] = $row;
            }
        }

        foreach ($sections as $number => $rows) {
            usort($rows, static fn (array $a, array $b): int => [$a['achievement']->getStartsOn(), $a['achievement']->getId()] <=> [$b['achievement']->getStartsOn(), $b['achievement']->getId()]);
            $sections[$number] = $rows;
        }

        return new SynthesisTable(
            $studentName,
            $portfolio->getCandidateNumber(),
            $trainingCentre,
            $options,
            $portfolio->getExternalUrl(),
            $session,
            $columns,
            $sections,
            $coverage,
            $includePending,
        );
    }

    /** « MARTIN Léa » - the way the examination documents write a candidate. */
    public static function officialName(?string $lastname, ?string $firstname, string $fallback): string
    {
        $name = trim(mb_strtoupper($lastname ?? '').' '.($firstname ?? ''));

        return '' === $name ? $fallback : $name;
    }

    /** « 02/03/26 au 27/03/26 », the form the template's column head asks for. */
    public static function period(PortfolioAchievement $achievement): string
    {
        $start = $achievement->getStartsOn();
        $end = $achievement->getEndsOn();

        $from = $start ?? $end;
        $until = $end ?? $start;

        if (null === $from || null === $until) {
            return '';
        }

        return $from->format('d/m/y').' au '.$until->format('d/m/y');
    }

    /** The « liste des documents et productions associés », one line. */
    public static function documents(PortfolioAchievement $achievement): string
    {
        return implode(' · ', array_map(static fn (PortfolioEvidence $evidence): string => PortfolioEvidenceKind::Link === $evidence->getKind()
            ? (string) ($evidence->getLabel() === $evidence->getUrl() ? $evidence->getUrl() : $evidence->getLabel().' ('.$evidence->getUrl().')')
            : $evidence->getLabel(), $achievement->getEvidences()->toArray()));
    }
}
