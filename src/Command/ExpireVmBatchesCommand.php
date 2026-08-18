<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\VmBatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports the batches whose date has passed.
 *
 * **It destroys nothing**, and that is not a limitation of this command - it is the application's
 * one frozen rule. A batch of twenty-four machines built for a six-week module is exactly the thing
 * nobody remembers to clean up, so the expiry date exists to make somebody notice; the deletion is
 * done in Proxmox, by a person, who is the only one who knows whether a student is mid-project.
 *
 * Each batch is reminded about **once**. A reminder that arrives every day is a reminder nobody
 * reads, and the expired machines are not doing any harm in the meantime.
 */
#[AsCommand(
    name: 'app:proxmox:expire-batches',
    description: 'Signale les lots de machines dont la date est passée. Ne supprime rien.',
)]
class ExpireVmBatchesCommand extends Command
{
    public function __construct(
        private readonly VmBatchRepository $batches,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste sans marquer les lots comme signalés');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        $expired = $this->batches->findExpiredNeedingReminder(new \DateTimeImmutable('today'));

        if ([] === $expired) {
            $io->success('Aucun lot expiré à signaler.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($expired as $batch) {
            $rows[] = [
                $batch->getLabel(),
                $batch->getProgram()?->getShortName() ?? '—',
                $batch->getExpiresAt()?->format('d/m/Y') ?? '—',
                \count($batch->getItems()),
                $batch->getHost()?->getLabel() ?? '—',
            ];

            if (!$dryRun) {
                $batch->markReminded();
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(['Lot', 'Formation', 'Expiré le', 'Machines', 'Hôte'], $rows);
        $io->warning(\sprintf(
            '%d lot(s) expiré(s). Les machines existent toujours : la suppression se fait dans Proxmox.',
            \count($rows),
        ));

        return Command::SUCCESS;
    }
}
