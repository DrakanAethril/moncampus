<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\Program;
use App\Enum\CrmPhase;
use App\Enum\CrmSituation;
use App\Enum\CrmStep;
use App\Repository\CrmDossierRepository;

/**
 * A formation's page: one list of its candidates, by surname then first name, each with its
 * modality and three marks - pré-inscription done, inscription done, dossier complete. The
 * resigned and cancelled dossiers are left out unless asked for; the export always has them.
 *
 * A stage reads « done » once its required pieces are all done, « sans objet » when it asks the
 * dossier nothing required (a re-enrolment has no pré-inscription, « Non renseignée » asks nothing),
 * and « to do » otherwise. « Complet » is CrmDossierProgress::step(), the overview's own reading.
 *
 * @phpstan-type BoardRow array{progress: CrmDossierProgress, stages: array<string, string>, complete: bool}
 */
final readonly class CrmBoard
{
    public const string OPTION_NONE = 'none';

    public const string DONE = 'done';
    public const string TODO = 'todo';
    public const string NOT_APPLICABLE = 'na';

    /** The stages the list shows, in their columns' order. */
    public const array STAGES = [CrmPhase::PreEnrolment, CrmPhase::Enrolment];

    public function __construct(
        private CrmDossierRepository $dossiers,
        private CrmSettings $settings,
    ) {
    }

    /** @return list<CrmDossierProgress> */
    public function progressesOf(Program $program): array
    {
        return array_map(fn ($d): CrmDossierProgress => $this->settings->progress($d), $this->dossiers->forPrograms([$program]));
    }

    /**
     * @return array{stats: CrmProgramStats, rows: list<BoardRow>, closedCount: int, showClosed: bool, empty: bool, filtered: bool, option: string|null, optionLinks: list<array{key: string|null, label: string|null, count: int}>, asksOption: bool}
     */
    public function build(Program $program, ?string $option, bool $showClosed): array
    {
        $all = $this->progressesOf($program);
        $asksOption = $this->settings->asksOption($program);
        $option = $asksOption ? $option : null;
        $shown = self::inOption($all, $option);

        $closed = array_filter($shown, static fn (CrmDossierProgress $p): bool => $p->dossier()->getSituation()->isClosed());
        $listed = $showClosed ? $shown : array_filter($shown, static fn (CrmDossierProgress $p): bool => !$p->dossier()->getSituation()->isClosed());

        $optionLinks = [];
        if ($asksOption) {
            $countActive = static fn (callable $filter): int => \count(array_filter($all, static fn (CrmDossierProgress $p): bool => CrmSituation::Active === $p->dossier()->getSituation() && $filter($p)));
            $optionLinks[] = ['key' => null, 'label' => null, 'count' => $countActive(static fn (): bool => true)];
            foreach ($this->settings->optionLines($program, $all) as $programOption) {
                $optionLinks[] = ['key' => (string) $programOption->getId(), 'label' => $programOption->getShortName(), 'count' => $countActive(static fn (CrmDossierProgress $p): bool => $p->dossier()->getOption() === $programOption)];
            }
            if ([] !== array_filter($all, static fn (CrmDossierProgress $p): bool => null === $p->dossier()->getOption())) {
                $optionLinks[] = ['key' => self::OPTION_NONE, 'label' => '', 'count' => $countActive(static fn (CrmDossierProgress $p): bool => null === $p->dossier()->getOption())];
            }
        }

        return [
            'stats' => CrmProgramStats::of($shown),
            'rows' => array_map(self::row(...), self::sorted($listed)),
            'closedCount' => \count($closed),
            'showClosed' => $showClosed,
            'empty' => [] === $all,
            'filtered' => \count($shown) < \count($all),
            'option' => $option,
            'optionLinks' => $optionLinks,
            'asksOption' => $asksOption,
        ];
    }

    /**
     * The export: the same list with every situation, the resigned and cancelled included.
     *
     * @return list<BoardRow>
     */
    public function exportRows(Program $program, ?string $option): array
    {
        $option = $this->settings->asksOption($program) ? $option : null;

        return array_map(self::row(...), self::sorted(self::inOption($this->progressesOf($program), $option)));
    }

    /** One stage of one dossier: done, to do, or not asked of it. */
    public static function stageState(CrmDossierProgress $progress, CrmPhase $phase): string
    {
        $count = $progress->phaseCount($phase);
        if (0 === $count['total']) {
            return self::NOT_APPLICABLE;
        }

        return $count['done'] === $count['total'] ? self::DONE : self::TODO;
    }

    /** @return BoardRow */
    private static function row(CrmDossierProgress $progress): array
    {
        $stages = [];
        foreach (self::STAGES as $phase) {
            $stages[$phase->value] = self::stageState($progress, $phase);
        }

        return ['progress' => $progress, 'stages' => $stages, 'complete' => CrmStep::Complete === $progress->step()];
    }

    /**
     * @param list<CrmDossierProgress> $all
     *
     * @return list<CrmDossierProgress>
     */
    private static function inOption(array $all, ?string $option): array
    {
        if (null === $option) {
            return $all;
        }

        return array_values(array_filter($all, static fn (CrmDossierProgress $p): bool => self::OPTION_NONE === $option
            ? null === $p->dossier()->getOption()
            : (string) $p->dossier()->getOption()?->getId() === $option));
    }

    /**
     * Surname, then first name, the French way: accents and case do not move a name.
     *
     * @param array<array-key, CrmDossierProgress> $progresses
     *
     * @return list<CrmDossierProgress>
     */
    private static function sorted(array $progresses): array
    {
        $collator = new \Collator('fr_FR');
        $progresses = array_values($progresses);
        usort($progresses, static function (CrmDossierProgress $a, CrmDossierProgress $b) use ($collator): int {
            $left = $a->dossier()->getCandidate();
            $right = $b->dossier()->getCandidate();

            return (int) $collator->compare($left->getLastName(), $right->getLastName())
                ?: (int) $collator->compare($left->getFirstName(), $right->getFirstName())
                ?: ($a->dossier()->getId() ?? 0) <=> ($b->dossier()->getId() ?? 0);
        });

        return $progresses;
    }
}
