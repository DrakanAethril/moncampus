<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ConsoleSessionRepository;
use App\Repository\PlatformActivityRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rolling retention of the platform log: beyond 12 months, rows are deleted. One row per login is
 * what makes the table grow - App\Entity\UfaActivity, by contrast, is not purged, its volume is small
 * and its events are the story of a booklet.
 *
 * **A second family since the machine console: console sessions, at 90 days.** Their own retention
 * rather than the platform log's, and much shorter, because what they hold is different in kind - a
 * transcript is up to 256 KiB of what was on somebody's screen, and keeping a year of those would be
 * both a volume and a thing to answer for. One command rather than two: it already runs daily, and
 * the question it answers is the same one.
 *
 * To be wired to a scheduled task (once a day is more than enough). With no scheduler, the command
 * stays usable by hand; nothing breaks if it never runs, the tables simply grow.
 */
#[AsCommand(
    name: 'app:purge-platform-activity',
    description: 'Supprime les entrées du journal plateforme antérieures à la durée de rétention.',
)]
class PurgePlatformActivityCommand extends Command
{
    private const int DEFAULT_RETENTION_MONTHS = 12;

    /** Console sessions, and their transcripts. Ninety days - see the class docblock. */
    private const int CONSOLE_RETENTION_DAYS = 90;

    public function __construct(
        private readonly PlatformActivityRepository $repository,
        private readonly ConsoleSessionRepository $consoleSessions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('months', null, InputOption::VALUE_REQUIRED, 'Durée de rétention en mois', self::DEFAULT_RETENTION_MONTHS);
        $this->addOption('console-days', null, InputOption::VALUE_REQUIRED, 'Rétention des sessions de console, en jours', self::CONSOLE_RETENTION_DAYS);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte sans supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $months = max(1, (int) $input->getOption('months'));
        $threshold = new \DateTimeImmutable(\sprintf('-%d months', $months));
        $consoleDays = max(1, (int) $input->getOption('console-days'));
        $consoleThreshold = new \DateTimeImmutable(\sprintf('-%d days', $consoleDays));

        if ($input->getOption('dry-run')) {
            $count = (int) $this->repository->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.occurredAt < :threshold')
                ->setParameter('threshold', $threshold)
                ->getQuery()
                ->getSingleScalarResult();

            $io->info(\sprintf('%d entrée(s) antérieure(s) au %s seraient supprimées.', $count, $threshold->format('d/m/Y')));
            $io->info(\sprintf(
                '%d session(s) de console antérieure(s) au %s seraient supprimées.',
                $this->consoleSessions->countOlderThan($consoleThreshold),
                $consoleThreshold->format('d/m/Y'),
            ));

            return Command::SUCCESS;
        }

        $deleted = $this->repository->deleteOlderThan($threshold);
        $io->success(\sprintf('%d entrée(s) antérieure(s) au %s supprimée(s).', $deleted, $threshold->format('d/m/Y')));

        $consoles = $this->consoleSessions->deleteOlderThan($consoleThreshold);
        $io->success(\sprintf('%d session(s) de console antérieure(s) au %s supprimée(s).', $consoles, $consoleThreshold->format('d/m/Y')));

        return Command::SUCCESS;
    }
}
