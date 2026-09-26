<?php

declare(strict_types=1);

namespace App\Command;

use App\Counter\CounterRecomputer;
use App\Counter\CounterRun;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The safety net of every stored counter of the platform (App\Counter\RecomputableCounter).
 *
 * **Cron, once a night.** The counters move in real time with what changes them; this pass only
 * catches what drifted, and says so - each correction is logged at error level, so in production a
 * drift reaches Discord instead of being patched quietly (App\Counter\CounterRecomputer).
 *
 * `--dry-run` compares without writing, which is how a suspicion is checked by hand. The exit code
 * is 0 even when something was corrected: correcting is the command's job, and the alert is the log.
 */
#[AsCommand(
    name: 'app:counters:recompute',
    description: 'Recalcule les compteurs stockés de la plateforme et corrige ceux qui ont dérivé.',
)]
class RecomputeCountersCommand extends Command
{
    public function __construct(private readonly CounterRecomputer $recomputer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('counter', null, InputOption::VALUE_REQUIRED, 'Un seul compteur, par son nom (sans l\'option : tous)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compare sans rien corriger');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $name = $input->getOption('counter');

        if (\is_string($name) && '' !== $name) {
            if (!$this->recomputer->has($name)) {
                $io->error(\sprintf('Compteur inconnu « %s ». Compteurs : %s.', $name, implode(', ', array_keys($this->recomputer->counters()))));

                return Command::INVALID;
            }

            $runs = [$this->recomputer->recompute($name, null, $dryRun)];
        } else {
            $runs = $this->recomputer->recomputeAll($dryRun);
        }

        foreach ($runs as $run) {
            $this->report($io, $run);
        }

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io, CounterRun $run): void
    {
        $io->section($run->counter);

        if ([] === $run->drifts) {
            $io->writeln(\sprintf('%d ligne(s) vérifiée(s), aucun écart.', $run->checked));

            return;
        }

        $io->writeln(\sprintf(
            '%d ligne(s) vérifiée(s), %d écart(s) %s :',
            $run->checked,
            \count($run->drifts),
            $run->dryRun ? 'trouvé(s), rien corrigé' : 'corrigé(s)',
        ));

        foreach ($run->drifts as $drift) {
            $io->writeln(\sprintf('  #%d %s : %s', $drift->id, $drift->label, $drift->describe()));
        }
    }
}
