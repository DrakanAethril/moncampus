<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PlatformActivityRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rétention glissante du journal plateforme : au-delà de 12 mois, on supprime. Une ligne par
 * connexion, c'est la table qui grossit - App\Entity\UfaActivity, elle, n'est pas purgée, son
 * volume est faible et ses événements sont l'histoire d'un livret.
 *
 * À brancher sur une tâche planifiée (une fois par jour suffit largement). Sans planificateur, la
 * commande reste utilisable à la main ; rien ne casse si elle ne tourne jamais, la table grossit,
 * simplement.
 */
#[AsCommand(
    name: 'app:purge-platform-activity',
    description: 'Supprime les entrées du journal plateforme antérieures à la durée de rétention.',
)]
class PurgePlatformActivityCommand extends Command
{
    private const int DEFAULT_RETENTION_MONTHS = 12;

    public function __construct(private readonly PlatformActivityRepository $repository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('months', null, InputOption::VALUE_REQUIRED, 'Durée de rétention en mois', self::DEFAULT_RETENTION_MONTHS);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte sans supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $months = max(1, (int) $input->getOption('months'));
        $threshold = new \DateTimeImmutable(\sprintf('-%d months', $months));

        if ($input->getOption('dry-run')) {
            $count = (int) $this->repository->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.occurredAt < :threshold')
                ->setParameter('threshold', $threshold)
                ->getQuery()
                ->getSingleScalarResult();

            $io->info(\sprintf('%d entrée(s) antérieure(s) au %s seraient supprimées.', $count, $threshold->format('d/m/Y')));

            return Command::SUCCESS;
        }

        $deleted = $this->repository->deleteOlderThan($threshold);
        $io->success(\sprintf('%d entrée(s) antérieure(s) au %s supprimée(s).', $deleted, $threshold->format('d/m/Y')));

        return Command::SUCCESS;
    }
}
