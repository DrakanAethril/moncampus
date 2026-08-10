<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;

/**
 * The volumetry of this application, measured on the application itself.
 *
 * Feeds the "Description technique" screen, which is written for students - and for a student the
 * interesting property of these numbers is that they are checkable: entities come from Doctrine's
 * own metadata, routes from the compiled router, everything else from counting the files that were
 * deployed. Nothing here is typed by hand.
 *
 * Two figures cannot be measured this way, because .git/ and tests/ are excluded from the
 * production image: the number of commits and the number of tests. They come from
 * App\Service\MeasuredProfile, with the date they were taken, and the screen shows that date.
 *
 * Computed once per worker: FrankenPHP keeps the kernel in memory between requests, so the file
 * walk happens on the first view and never again for that worker.
 *
 * @phpstan-type Figures array{
 *     entities: int,
 *     routes: int,
 *     controllers: int,
 *     services: int,
 *     repositories: int,
 *     enums: int,
 *     voters: int,
 *     forms: int,
 *     commands: int,
 *     phpFiles: int,
 *     phpLines: int,
 *     templates: int,
 *     twigLines: int,
 *     stimulusControllers: int,
 *     cssLines: int,
 *     migrations: int,
 *     releases: int,
 *     commits: int,
 *     tests: int,
 *     measuredAt: string
 * }
 */
class TechnicalProfile
{
    /** @var Figures|null */
    private ?array $figures = null;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
        private readonly SourceCounter $counter,
        private readonly ManagerRegistry $doctrine,
        private readonly RouterInterface $router,
        private readonly Changelog $changelog,
        private readonly MeasuredProfile $measured,
    ) {
    }

    /** @return Figures */
    public function figures(): array
    {
        if (null !== $this->figures) {
            return $this->figures;
        }

        $src = $this->projectDir.'/src';

        return $this->figures = [
            // Doctrine's own metadata rather than a file count: a mapped superclass is not an
            // entity, and an entity is not always one file.
            'entities' => count($this->doctrine->getManager()->getMetadataFactory()->getAllMetadata()),
            'routes' => count($this->router->getRouteCollection()),
            'controllers' => $this->counter->files($src.'/Controller', 'php'),
            'services' => $this->counter->files($src.'/Service', 'php'),
            'repositories' => $this->counter->files($src.'/Repository', 'php'),
            'enums' => $this->counter->files($src.'/Enum', 'php'),
            'voters' => $this->counter->files($src.'/Security/Voter', 'php'),
            'forms' => $this->counter->files($src.'/Form', 'php'),
            'commands' => $this->counter->files($src.'/Command', 'php'),
            'phpFiles' => $this->counter->files($src, 'php'),
            'phpLines' => $this->counter->lines($src, 'php'),
            'templates' => $this->counter->files($this->projectDir.'/templates', 'twig'),
            'twigLines' => $this->counter->lines($this->projectDir.'/templates', 'twig'),
            'stimulusControllers' => $this->counter->files($this->projectDir.'/assets/controllers', 'js', '_controller'),
            'cssLines' => $this->counter->lines($this->projectDir.'/assets/styles', 'css'),
            'migrations' => $this->counter->files($this->projectDir.'/migrations', 'php'),
            // One release per production deploy - the changelog is the record of those.
            'releases' => count($this->changelog->releases()),
            'commits' => $this->measured->commits(),
            'tests' => $this->measured->tests(),
            'measuredAt' => $this->measured->measuredAt(),
        ];
    }
}
