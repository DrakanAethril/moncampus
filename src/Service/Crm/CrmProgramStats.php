<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Enum\CrmModality;
use App\Enum\CrmSituation;
use App\Enum\CrmStep;

/**
 * A formation's figures - the overview's line, the formation's head. Only active dossiers are
 * counted in its numbers; the waiting and the closed ones are counted apart. « Effectif bas » is
 * the active dossiers less the visas still pending.
 *
 * Sums are only ever made over the formations of one year: no figure adds two years up.
 */
final readonly class CrmProgramStats
{
    /**
     * @param array<string, int> $steps active dossiers by step (CrmStep values)
     */
    private function __construct(
        public int $active,
        public array $steps,
        public int $initial,
        public int $apprenticeship,
        public int $other,
        public int $visaPending,
        public int $low,
        public int $waiting,
        public int $closed,
    ) {
    }

    /** @param list<CrmDossierProgress> $progresses */
    public static function of(array $progresses): self
    {
        $steps = array_fill_keys(array_map(static fn (CrmStep $s): string => $s->value, CrmStep::cases()), 0);
        $active = $initial = $apprenticeship = $other = $visa = $waiting = $closed = 0;

        foreach ($progresses as $progress) {
            $dossier = $progress->dossier();
            $situation = $dossier->getSituation();
            if ($situation->isWaiting()) {
                ++$waiting;
            }
            if ($situation->isClosed()) {
                ++$closed;
            }
            if (CrmSituation::Active !== $situation) {
                continue;
            }

            ++$active;
            ++$steps[$progress->step()->value];
            match ($dossier->getModality()) {
                CrmModality::Initial => ++$initial,
                CrmModality::Apprenticeship => ++$apprenticeship,
                CrmModality::Other => ++$other,
            };
            if ($progress->visaPending()) {
                ++$visa;
            }
        }

        return new self($active, $steps, $initial, $apprenticeship, $other, $visa, $active - $visa, $waiting, $closed);
    }

    /** @param list<self> $all the formations of one year */
    public static function sum(array $all): self
    {
        $steps = array_fill_keys(array_map(static fn (CrmStep $s): string => $s->value, CrmStep::cases()), 0);
        foreach ($all as $stats) {
            foreach ($stats->steps as $step => $count) {
                $steps[$step] += $count;
            }
        }
        $add = static fn (string $field): int => array_sum(array_map(static fn (self $s): int => $s->{$field}, $all));

        return new self($add('active'), $steps, $add('initial'), $add('apprenticeship'), $add('other'), $add('visaPending'), $add('low'), $add('waiting'), $add('closed'));
    }

    /** The share of each step in the bar, in percent, for the active dossiers. */
    public function share(string $step): float
    {
        return 0 === $this->active ? 0.0 : round(($this->steps[$step] ?? 0) / $this->active * 100, 2);
    }
}
