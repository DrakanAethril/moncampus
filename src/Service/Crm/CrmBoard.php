<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmPiece;
use App\Entity\Program;
use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceConstraint;
use App\Enum\CrmSituation;
use App\Repository\CrmDossierRepository;

/**
 * A formation's page: one accordion per stage, a column per piece, a row per dossier at that stage
 * (and, ticked, the ones that finished it), grouped by situation; the documents split by modality.
 *
 * A column is drawn when the piece concerns at least one dossier of the formation - its path, a
 * foreign student for a foreign-only piece - and the formation does not hide it; a cell whose piece
 * does not apply to that dossier reads « — ».
 */
final readonly class CrmBoard
{
    public const string OPTION_NONE = 'none';

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
     * @param list<string> $showDone the stages whose finished dossiers are shown too (CrmPhase values)
     *
     * @return array<string, mixed>
     */
    public function build(Program $program, ?string $option, array $showDone): array
    {
        $all = $this->progressesOf($program);
        $settings = $this->settings->settingsOf($program);
        $asksOption = $this->settings->asksOption($program);
        $option = $asksOption ? $option : null;

        $matches = static fn (CrmDossierProgress $p): bool => null === $option
            || (self::OPTION_NONE === $option ? null === $p->dossier()->getOption() : (string) $p->dossier()->getOption()?->getId() === $option);
        $shown = array_values(array_filter($all, $matches));

        $paths = [];
        $anyForeign = false;
        foreach ($all as $progress) {
            $paths[$progress->dossier()->getPath()->value] = true;
            $anyForeign = $anyForeign || $progress->dossier()->getCandidate()->isForeignStudent();
        }
        if ([] === $paths) {
            $paths[CrmPath::Enrolment->value] = true;
        }
        $fits = static fn (CrmPiece $piece): bool => [] !== array_filter($piece->getPaths(), static fn (CrmPath $path): bool => isset($paths[$path->value]))
            && CrmPieceConstraint::Hidden !== CrmDossierProgress::constraint($piece, $settings)
            && (!$piece->isForeignOnly() || $anyForeign);

        $phases = [];
        foreach (CrmPhase::cases() as $phase) {
            $done = \in_array($phase->value, $showDone, true);
            $showIn = static fn (CrmDossierProgress $p): bool => $phase->step() === $p->step() || ($done && $p->isPhaseDone($phase));
            $active = array_values(array_filter($all, static fn (CrmDossierProgress $p): bool => CrmSituation::Active === $p->dossier()->getSituation()));
            $withPhase = array_filter($active, static fn (CrmDossierProgress $p): bool => $p->phaseCount($phase)['total'] > 0);

            $entry = [
                'phase' => $phase,
                'here' => \count(array_filter($active, static fn (CrmDossierProgress $p): bool => $phase->step() === $p->step())),
                'finished' => \count(array_filter($withPhase, static fn (CrmDossierProgress $p): bool => $p->isPhaseDone($phase))),
                'withPhase' => \count($withPhase),
                'doneCount' => \count(array_filter($shown, static fn (CrmDossierProgress $p): bool => $p->isPhaseDone($phase))),
                'showDone' => $done,
                'open' => [] !== array_filter($shown, static fn (CrmDossierProgress $p): bool => $phase->step() === $p->step()) || ([] === $all && CrmPhase::PreEnrolment === $phase),
                'tables' => [],
            ];

            $catalogue = array_values(array_filter($this->settings->catalogue(), static fn (CrmPiece $piece): bool => $phase === $piece->getPhase() && $fits($piece)));
            $pool = array_values(array_filter($shown, $showIn));

            if (CrmPhase::Documents !== $phase) {
                $entry['skipped'] = [] === $catalogue;
                if ([] !== $catalogue) {
                    $entry['tables'][] = ['modality' => null, 'columns' => $catalogue, 'groups' => self::groups($pool, true), 'count' => \count($pool)];
                }
            } else {
                $entry['skipped'] = false;
                foreach (CrmModality::cases() as $modality) {
                    $rows = array_values(array_filter($pool, static fn (CrmDossierProgress $p): bool => $modality === $p->dossier()->getModality()));
                    if ([] === $rows) {
                        continue;
                    }
                    $columns = array_values(array_filter($catalogue, static fn (CrmPiece $piece): bool => $piece->isFor($modality)));
                    $entry['tables'][] = ['modality' => $modality, 'columns' => $columns, 'groups' => self::groups($rows, false), 'count' => \count($rows)];
                }
            }
            $entry['rowCount'] = array_sum(array_map(static fn (array $t): int => $t['count'], $entry['tables']));
            $phases[] = $entry;
        }

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
            'phases' => $phases,
            'empty' => [] === $all,
            'filtered' => \count($shown) < \count($all),
            'option' => $option,
            'optionLinks' => $optionLinks,
            'asksOption' => $asksOption,
        ];
    }

    /**
     * @param list<CrmDossierProgress> $rows
     *
     * @return list<array{key: string, labelKey: string|null, rows: list<CrmDossierProgress>}>
     */
    private static function groups(array $rows, bool $labelActive): array
    {
        $groups = [
            ['key' => 'active', 'labelKey' => $labelActive ? 'crmGroupActiveLabel' : null, 'filter' => static fn (CrmSituation $s): bool => CrmSituation::Active === $s],
            ['key' => 'pending', 'labelKey' => 'crmGroupPendingLabel', 'filter' => static fn (CrmSituation $s): bool => CrmSituation::Pending === $s],
            ['key' => 'waitlist', 'labelKey' => 'crmGroupWaitlistLabel', 'filter' => static fn (CrmSituation $s): bool => CrmSituation::Waitlist === $s],
            ['key' => 'closed', 'labelKey' => 'crmGroupClosedLabel', 'filter' => static fn (CrmSituation $s): bool => $s->isClosed()],
        ];

        $out = [];
        foreach ($groups as $group) {
            $list = array_values(array_filter($rows, static fn (CrmDossierProgress $p): bool => $group['filter']($p->dossier()->getSituation())));
            if ([] !== $list) {
                $out[] = ['key' => $group['key'], 'labelKey' => $group['labelKey'], 'rows' => $list];
            }
        }

        return $out;
    }
}
