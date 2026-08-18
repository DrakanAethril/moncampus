<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ProxmoxHost;
use App\Repository\ProxmoxHostRepository;
use App\Service\Proxmox\ProxmoxHostChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tests every declared hypervisor and records what it found.
 *
 * This is what keeps the badges honest. The console never probes a host while a page renders - a
 * list that sounds out N hypervisors is as slow as the worst and as broken as the one that is
 * unplugged - so the screens show the *last known* state, and something has to go and refresh it.
 * A cron every few minutes is the intent; run by hand it is also the quickest way to find out why
 * a host will not answer, since it prints the reason rather than a badge.
 *
 * Exits non-zero when a host is unreachable, so a scheduler notices. `--dry-run` tests without
 * writing anything down, which is what you want when investigating rather than monitoring.
 */
#[AsCommand(
    name: 'app:proxmox:check',
    description: 'Teste chaque hôte Proxmox déclaré et enregistre son état.',
)]
class CheckProxmoxHostsCommand extends Command
{
    public function __construct(
        private readonly ProxmoxHostRepository $repository,
        private readonly ProxmoxHostChecker $checker,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Teste sans rien enregistrer');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Ne tester qu’un hôte, par identifiant');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');
        // InputInterface::getOption() answers mixed by design - narrow it here rather than casting
        // at the point of use, same rule as every other boundary in this application.
        $onlyOption = $input->getOption('host');
        $only = is_numeric($onlyOption) ? (int) $onlyOption : null;

        $hosts = $this->repository->findOrdered();

        if (null !== $only) {
            $hosts = array_values(array_filter($hosts, static fn (ProxmoxHost $host): bool => $host->getId() === $only));
        }

        if ([] === $hosts) {
            $io->warning('Aucun hôte Proxmox actif n’est déclaré.');

            return Command::SUCCESS;
        }

        $rows = [];
        $failures = 0;

        foreach ($hosts as $host) {
            $result = $this->checker->check($host);

            if (!$result->ok) {
                ++$failures;
            }

            $rows[] = [
                $host->getLabel(),
                $host->getDisplayAddress(),
                $result->ok ? 'OK' : 'KO',
                $result->version ?? '—',
                null !== $result->guestCount ? \sprintf('%d (%d actives)', $result->guestCount, $result->runningCount ?? 0) : '—',
                $result->ok ? implode(' ; ', array_map(static fn ($warning): string => $warning->messageKey, $result->warnings)) : $result->message,
            ];
        }

        if ($dryRun) {
            // Nothing is flushed, so the recorded state stays exactly as it was: a dry run must not
            // move the badges an administrator is looking at.
            $this->entityManager->clear();
        } else {
            $this->entityManager->flush();
        }

        $io->table(['Hôte', 'Adresse', 'État', 'Version', 'Machines', 'Détail'], $rows);

        if ($failures > 0) {
            $io->error(\sprintf('%d hôte(s) sur %d ne répondent pas.', $failures, \count($rows)));

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d hôte(s) joignable(s).', \count($rows)));

        return Command::SUCCESS;
    }
}
