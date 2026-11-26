<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmDossier;
use App\Entity\CrmPiece;
use App\Enum\CrmPhase;
use App\Repository\CrmDossierRepository;

/**
 * « Action requise » on the staff's home: the pieces ticked « Remonter sur l'accueil »
 * (CrmPiece::$homeAlert) that a dossier awaits - its stage reached, the piece not filled in
 * (CrmDossierProgress::awaits()). One group per stage, in the stages' order, the follow-up
 * documents last; one table per piece, in the catalogue's order; the dossiers by surname.
 *
 * Read over every year opened in the CRM and its formations followed; a resigned or cancelled
 * dossier waits on nothing.
 */
final readonly class CrmActionRequired
{
    public function __construct(
        private CrmSettings $settings,
        private CrmDossierRepository $dossiers,
    ) {
    }

    /**
     * @return array{groups: list<array{phase: CrmPhase, pieces: list<array{piece: CrmPiece, dossiers: list<CrmDossier>}>}>, years: int}
     */
    public function read(): array
    {
        $pieces = array_values(array_filter($this->settings->catalogue(), static fn (CrmPiece $p): bool => $p->isHomeAlert()));
        $years = $this->settings->openedYears();
        if ([] === $pieces || [] === $years) {
            return ['groups' => [], 'years' => \count($years)];
        }

        $programs = [];
        foreach ($years as $year) {
            array_push($programs, ...$this->settings->followedPrograms($year));
        }

        /** @var array<int, list<CrmDossier>> $waiting by piece id */
        $waiting = [];
        foreach ($this->dossiers->forPrograms($programs) as $dossier) {
            if ($dossier->getSituation()->isClosed()) {
                continue;
            }
            $progress = $this->settings->progress($dossier);
            foreach ($pieces as $piece) {
                if ($progress->awaits($piece)) {
                    $waiting[(int) $piece->getId()][] = $dossier;
                }
            }
        }

        $groups = [];
        foreach (CrmPhase::cases() as $phase) {
            $tables = [];
            foreach ($pieces as $piece) {
                if ($phase === $piece->getPhase() && [] !== ($waiting[(int) $piece->getId()] ?? [])) {
                    $tables[] = ['piece' => $piece, 'dossiers' => $waiting[(int) $piece->getId()]];
                }
            }
            if ([] !== $tables) {
                $groups[] = ['phase' => $phase, 'pieces' => $tables];
            }
        }

        return ['groups' => $groups, 'years' => \count($years)];
    }
}
