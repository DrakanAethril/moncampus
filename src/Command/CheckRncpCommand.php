<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ReferentialRepository;
use App\Service\Rncp\RncpExportLocator;
use App\Service\Rncp\RncpFicheReader;
use App\Service\Rncp\RncpReadException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The weekly watch on the référentiels' RNCP fiches (design/validated/portfolio.md §8).
 *
 * For every référentiel imported from France compétences, reads the fiche again in the current
 * export and records what it says - still active, end of registration, and whether another fiche
 * now names it among the ones it replaces (a renovated diploma). The Référentiels screen turns a
 * change into a banner; nothing is ever rewritten here: a new fiche is a new version the
 * administrator creates (R1, R14).
 *
 * A change is logged at **warning** level, not error: it is news for the administrator, not a fault
 * of the platform, and Discord is for faults. An unreachable data.gouv.fr is a warning too, and the
 * next week tries again.
 */
#[AsCommand(
    name: 'app:rncp:check',
    description: 'Relit chaque semaine les fiches RNCP des référentiels (active, fin d’enregistrement, fiche remplaçante).',
)]
class CheckRncpCommand extends Command
{
    use SharedLockableTrait;

    public function __construct(
        private readonly ReferentialRepository $referentials,
        private readonly RncpExportLocator $locator,
        private readonly RncpFicheReader $reader,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Lire cet export local (zip ou xml) au lieu de le télécharger.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->comment('Une autre exécution est déjà en cours.');

            return Command::SUCCESS;
        }

        $watched = array_values(array_filter($this->referentials->findAll(), static fn ($referential): bool => null !== $referential->getRncpCode()));

        if ([] === $watched) {
            $io->comment('Aucun référentiel lié à une fiche RNCP.');

            return Command::SUCCESS;
        }

        $fileOption = $input->getOption('file');

        try {
            $path = \is_string($fileOption) && '' !== $fileOption ? $fileOption : $this->locator->fetchLatest()['path'];
        } catch (RncpReadException $exception) {
            $this->logger->warning('RNCP watch: the export could not be fetched.', ['error' => $exception->getMessage()]);
            $io->warning($exception->getMessage());

            return Command::SUCCESS;
        }

        $uri = str_ends_with(strtolower($path), '.zip') ? null : $path;

        foreach ($watched as $referential) {
            $code = (string) $referential->getRncpCode();

            try {
                $fiche = null === $uri ? $this->reader->readFromZip($path, $code) : $this->reader->readFromUri($uri, $code);
                $replacedBy = null === $uri ? $this->reader->replacingFromZip($path, $code) : $this->reader->replacing($uri, $code);
            } catch (RncpReadException $exception) {
                $io->warning($code.' : '.$exception->getMessage());
                continue;
            }

            $before = $referential->getRncpAlerts();
            $referential->setRncpWatch([
                'checkedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'found' => null !== $fiche,
                'active' => null === $fiche ? false : $fiche['actif'],
                'registeredUntil' => $fiche['dateFinEnregistrement'] ?? null,
                'replacedBy' => $replacedBy,
            ]);
            $after = $referential->getRncpAlerts();

            if ([] !== array_diff($after, $before)) {
                $this->logger->warning('RNCP watch: a référentiel fiche changed.', ['referential' => $referential->getDisplayName(), 'alerts' => $after]);
            }

            $io->writeln(\sprintf('%s : %s', $code, [] === $after ? 'inchangée' : implode(', ', $after)));
        }

        $this->entityManager->flush();

        return Command::SUCCESS;
    }
}
