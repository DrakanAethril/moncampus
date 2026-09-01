<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GuestAccount;
use App\Service\Guest\StaleGuestAccountPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Forgets the accounts declared inside machines that no longer exist.
 *
 * The state it exists for is one nobody sees from /infrastructure, and that is exactly what makes it
 * worth a command: those screens read the hypervisors as they render, so a machine destroyed in
 * Proxmox simply stops being listed there - while its `guest_account` rows stay, and those rows are
 * what « Mes machines virtuelles » is built from. A batch deleted afterwards takes the last visible
 * trace with it. The result is a card on a student's or a teacher's screen for a machine that exists
 * nowhere, and an administrator with no screen on which to notice it.
 *
 * **It never destroys anything on a hypervisor** and never can: it removes rows describing accounts
 * inside machines Proxmox has already answered it does not hold. A host that does not answer decides
 * nothing at all - those accounts are counted apart and left exactly as they are.
 *
 * Not a cron. Run it after a session of deleting batches, or when « Mes machines » shows something
 * /infrastructure does not. `--dry-run` names every row it would remove; run that first.
 */
#[AsCommand(
    name: 'app:guest-accounts:prune',
    description: 'Supprime les comptes déclarés dans des machines qui n\'existent plus sur le serveur de virtualisation.',
)]
class PruneGuestAccountsCommand extends Command
{
    public function __construct(private readonly StaleGuestAccountPruner $pruner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste sans supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        $report = $dryRun ? $this->pruner->inspectAll() : $this->pruner->pruneAll();

        if ([] !== $report->stale) {
            $io->table(
                ['Hôte', 'Nœud', 'VMID', 'Login', 'Titulaire', 'Lot'],
                array_map(static fn (GuestAccount $account): array => [
                    $account->getHost()?->getLabel() ?? '?',
                    $account->getNode(),
                    (string) $account->getVmid(),
                    $account->getLogin(),
                    $account->getDisplayName() ?? '-',
                    $account->getBatch()?->getLabel() ?? '-',
                ], $report->stale),
            );
        }

        $io->writeln(\sprintf('Machines encore présentes : %d compte(s) conservé(s).', $report->keptCount));

        if ([] !== $report->undecided) {
            // Named rather than counted into the same total: an unreachable hypervisor is the one
            // state where this command has decided nothing, and a pass that hid it would read as a
            // clean sweep.
            $io->warning(\sprintf(
                '%d compte(s) sur un hôte injoignable : rien n\'a été décidé les concernant. Relancer quand l\'hôte répond.',
                \count($report->undecided),
            ));
        }

        if ([] === $report->stale) {
            $io->success('Aucun compte orphelin.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note(\sprintf('%d compte(s) seraient supprimé(s). Relancer sans --dry-run pour appliquer.', \count($report->stale)));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d compte(s) orphelin(s) supprimé(s).', \count($report->stale)));

        return Command::SUCCESS;
    }
}
