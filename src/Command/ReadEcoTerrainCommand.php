<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EcoParcoursRepository;
use App\Service\Eco\EcoParcoursTerrainAnalyzer;
use App\Service\Eco\EcoPingTerrainResolver;
use App\Service\Ign\IgnUnavailableException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Everything e-CO asks of the IGN's Géoplateforme that is too slow for a request: a WFS page of
 * the BD TOPO takes seconds, a class's race is twenty thousand fixes. A pass does at most two
 * things, each bounded:
 *
 * 1. **the oldest parcours waiting for its terrain analysis** - asked by the « Analyser le
 *    terrain » button, by the last flag of a parcours being located, or by a race on a parcours
 *    never analysed - then the pairs of flags runners ran on it;
 * 2. **the oldest closed race whose GPS fixes the IGN has not been asked about** - altitude, path,
 *    wood - then, the same way, the pairs its runners ran.
 *
 * Scheduled every minute (App\Scheduler\PlatformSchedule). **A Géoplateforme that does not answer
 * is not a failure of this command**: the work stays waiting, a warning is logged, and the next
 * pass tries again. Exiting non-zero would page Discord for an outage of a public service nobody
 * here can fix.
 */
#[AsCommand(
    name: 'app:eco:read-terrain',
    description: 'Interroge l’IGN pour les parcours e-CO à analyser et les courses clôturées à relire.',
)]
class ReadEcoTerrainCommand extends Command
{
    use SharedLockableTrait;

    public function __construct(
        private readonly EcoParcoursRepository $parcoursRepository,
        private readonly EcoParcoursTerrainAnalyzer $analyzer,
        private readonly EcoPingTerrainResolver $resolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->writeln('Une autre relecture IGN est en cours.');

            return Command::SUCCESS;
        }

        $this->analyseNextParcours($io);
        $this->resolveNextCourse($io);

        return Command::SUCCESS;
    }

    private function analyseNextParcours(SymfonyStyle $io): void
    {
        $parcours = $this->parcoursRepository->findNextTerrainRequest();
        if (null === $parcours) {
            $io->writeln('Aucun parcours à analyser.');

            return;
        }

        if (0 === $parcours->getLocatedCheckpointCount()) {
            // Nothing located, nothing to read: the request is dropped, not kept looping.
            $parcours->recordTerrainAnalysis([], new \DateTimeImmutable());
            $this->entityManager->flush();

            return;
        }

        try {
            $analysis = $this->analyzer->analyze($parcours);
            $this->entityManager->flush();
        } catch (IgnUnavailableException $exception) {
            $this->warn('parcours '.$parcours->getId(), $exception, $io);

            return;
        }

        $routes = $this->resolver->completeRoutes($parcours);

        $io->writeln(\sprintf(
            '%s — %d tronçon(s) analysé(s)%s, %d itinéraire(s) de coureurs ajouté(s).',
            $parcours->getName() ?? '',
            \count($analysis['legs']),
            $analysis['incomplete'] ? ' (analyse incomplète)' : '',
            $routes,
        ));
    }

    private function resolveNextCourse(SymfonyStyle $io): void
    {
        try {
            $resolved = $this->resolver->resolveNextCourse();
        } catch (IgnUnavailableException $exception) {
            $this->warn('course relecture', $exception, $io);

            return;
        }

        if (null === $resolved) {
            $io->writeln('Aucune course clôturée à relire.');

            return;
        }

        $routes = $this->resolver->completeRoutes($resolved['course']->getParcours());

        $io->writeln(\sprintf(
            '%s — %d position(s) relue(s), %d itinéraire(s) de coureurs ajouté(s).',
            $resolved['course']->getName() ?? '',
            $resolved['pings'],
            $routes,
        ));
    }

    private function warn(string $what, IgnUnavailableException $exception, SymfonyStyle $io): void
    {
        $this->logger->warning('app:eco:read-terrain: IGN unavailable for {what}: {message}', [
            'what' => $what,
            'message' => $exception->getMessage(),
        ]);
        $io->writeln(\sprintf('IGN indisponible (%s) : %s — nouvel essai au prochain passage.', $what, $exception->getMessage()));
    }
}
