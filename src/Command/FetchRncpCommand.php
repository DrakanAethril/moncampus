<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\RncpImportRepository;
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
 * Serves the « Récupérer chez France compétences » requests (design/validated/portfolio.md §8).
 *
 * The screen only writes an App\Entity\RncpImport; this command, scheduled every minute, does the
 * work nobody should wait for in a web request - find the day's export on data.gouv.fr, download
 * it once (cached in `var/rncp/` for the day), stream through it for each requested fiche - and the
 * screen, polling every two seconds, shows the result as soon as it lands. It is the pattern of
 * app:ldap:apply-account-requests: the browser's loop never carries the work.
 *
 * **A network failure is not an error of this command**: the request goes to « En échec » with the
 * reason, the administrator reads it on screen and presses « Relancer ». It exits 0, so a
 * data.gouv.fr outage does not page anybody every minute.
 *
 * `--file=` reads a local export (zip or xml) instead of downloading - for the dev machine and for a
 * server that cannot reach data.gouv.fr.
 */
#[AsCommand(
    name: 'app:rncp:fetch',
    description: 'Récupère chez France compétences les fiches RNCP demandées depuis l’écran Référentiels.',
)]
class FetchRncpCommand extends Command
{
    use SharedLockableTrait;

    public function __construct(
        private readonly RncpImportRepository $imports,
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

        $pending = $this->imports->findPending();

        if ([] === $pending) {
            $io->comment('Aucune fiche à récupérer.');

            return Command::SUCCESS;
        }

        foreach ($pending as $import) {
            $import->start();
        }
        $this->entityManager->flush();

        $fileOption = $input->getOption('file');

        try {
            $source = \is_string($fileOption) && '' !== $fileOption
                ? ['path' => $fileOption, 'name' => basename($fileOption), 'date' => null]
                : $this->locator->fetchLatest();
        } catch (RncpReadException $exception) {
            foreach ($pending as $import) {
                $import->fail($exception->getMessage());
            }
            $this->entityManager->flush();
            $this->logger->warning('RNCP import: the export could not be fetched.', ['error' => $exception->getMessage()]);
            $io->warning($exception->getMessage());

            return Command::SUCCESS;
        }

        foreach ($pending as $import) {
            try {
                $fiche = str_ends_with(strtolower($source['path']), '.zip')
                    ? $this->reader->readFromZip($source['path'], $import->getRncpCode())
                    : $this->reader->readFromUri($source['path'], $import->getRncpCode());

                if (null === $fiche) {
                    $import->fail(\sprintf('La fiche %s ne figure pas dans l’export %s.', $import->getRncpCode(), $source['name']));
                } else {
                    $import->succeed($fiche, $source['name'], $source['date']);
                }
            } catch (RncpReadException $exception) {
                $import->fail($exception->getMessage());
            }

            $this->entityManager->flush();
            $io->writeln(\sprintf('%s : %s', $import->getRncpCode(), $import->getState()->value));
        }

        return Command::SUCCESS;
    }
}
