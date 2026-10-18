<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SeriousGameTemplate;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameTemplateRepository;
use App\SeriousGame\Engine\DatabaseTemplateSource;
use App\SeriousGame\Engine\FileTemplateSource;
use App\SeriousGame\Template\TemplateBench;
use App\SeriousGame\Template\TemplateDefinition;
use App\SeriousGame\Template\TemplateInterpreter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The bench of a game's question templates (design/validated/serious-games.md §5.3). **Diagnostic,
 * not scheduled.**.
 *
 * By default it reads the versions of the database - the current one of each unit, or the latest
 * draft with --draft - and, with --save, stores each report on its version, which is what makes a
 * draft validatable. With --files it reads `config/serious_games/{slug}/templates/` instead and
 * needs no database: that is how a template is written and checked before it is installed.
 */
#[AsCommand(
    name: 'app:serious-games:bench',
    description: 'Passe les gabarits d’un serious game au banc (énoncés, réponses, erreurs typiques, séries, comètes).',
)]
class SeriousGameBenchCommand extends Command
{
    public function __construct(
        private readonly SeriousGameRepository $games,
        private readonly SeriousGameTemplateRepository $templates,
        private readonly TemplateInterpreter $interpreter,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/config/serious_games')]
        private readonly string $directory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED, 'Le jeu (observatoire)');
        $this->addOption('unit', null, InputOption::VALUE_REQUIRED, 'Une seule unité');
        $this->addOption('seeds', null, InputOption::VALUE_REQUIRED, 'Nombre de graines par gabarit', '1000');
        $this->addOption('files', null, InputOption::VALUE_NONE, 'Lit les fichiers du dépôt plutôt que la base');
        $this->addOption('draft', null, InputOption::VALUE_NONE, 'Passe le dernier brouillon de chaque unité plutôt que sa version courante');
        $this->addOption('save', null, InputOption::VALUE_NONE, 'Enregistre le rapport sur chaque version passée au banc');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Écrit les rapports en JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = (string) $input->getArgument('slug');
        $unit = \is_string($input->getOption('unit')) ? $input->getOption('unit') : null;
        $seeds = max(1, min(5000, (int) $input->getOption('seeds')));
        $bench = new TemplateBench($this->interpreter);

        /** @var list<array{0: TemplateDefinition, 1: SeriousGameTemplate|null}> $targets */
        $targets = [];
        if (true === $input->getOption('files')) {
            $source = new FileTemplateSource($this->directory.'/'.$slug.'/templates');
            foreach ($source->units() as $code) {
                if (null === $unit || $unit === $code) {
                    $definition = $source->current($code);
                    if (null !== $definition) {
                        $targets[] = [$definition, null];
                    }
                }
            }
        } else {
            $game = $this->games->findOneBySlug($slug);
            if (null === $game) {
                $io->error('Jeu inconnu : '.$slug.' (lancer d’abord app:serious-games:sync, ou passer --files).');

                return Command::FAILURE;
            }
            $draft = true === $input->getOption('draft');
            $byUnit = [];
            foreach ($this->templates->findForGame($game) as $template) {
                if ((null === $unit || $unit === $template->getUnitCode()) && !isset($byUnit[$template->getUnitCode()]) && ($draft ? $template->isDraft() : $template->isValidated())) {
                    $byUnit[$template->getUnitCode()] = $template;
                }
            }
            foreach ($byUnit as $template) {
                $targets[] = [DatabaseTemplateSource::definition($template), $template];
            }
        }

        if ([] === $targets) {
            $io->warning('Aucun gabarit à passer au banc.');

            return Command::FAILURE;
        }

        $green = true;
        $reports = [];
        foreach ($targets as [$definition, $entity]) {
            $report = $bench->run($definition, $seeds);
            $reports[] = $report;
            $green = $green && $report['green'];
            if (null !== $entity && true === $input->getOption('save')) {
                $entity->setBenchReport($report);
            }

            if (true === $input->getOption('json')) {
                continue;
            }

            $line = \sprintf('%s v%d : %d questions, erreurs reconnues %d/%d, tirages max %d', $definition->unit, $definition->version, $report['questions'], $report['recognised'], $report['wrongs'], $report['maxAttempts']);
            if ($report['green']) {
                $io->writeln('<info>✔</info> '.$line.([] === $report['neverSeen'] ? '' : ' — jamais vues : '.implode(', ', $report['neverSeen'])));
            } else {
                $io->writeln('<error>✘</error> '.$line);
                foreach ($report['failures'] as $failure) {
                    $io->writeln('    '.$failure['rule'].(null === $failure['seed'] ? '' : ' [graine '.$failure['seed'].']').' : '.$failure['detail']);
                }
            }
        }

        if (true === $input->getOption('save')) {
            $this->entityManager->flush();
        }

        if (true === $input->getOption('json')) {
            $output->writeln((string) json_encode($reports, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        } elseif ($green) {
            $io->success(\count($targets).' gabarit(s) au vert.');
        } else {
            $io->error('Banc rouge.');
        }

        return $green ? Command::SUCCESS : Command::FAILURE;
    }
}
