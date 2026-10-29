<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameTemplate;
use App\Enum\SeriousGameMechanic;
use App\Repository\SeriousGameAnswerRepository;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameTemplateRepository;
use App\SeriousGame\Scenario\ScenarioManifest;
use App\SeriousGame\Scenario\ScenarioValidator;
use App\SeriousGame\SeriousGameManifest;
use App\SeriousGame\Template\TemplateBench;
use App\SeriousGame\Template\TemplateDefinition;
use App\SeriousGame\Template\TemplateFormatException;
use App\SeriousGame\Template\TemplateInterpreter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Installs the serious games shipped with the code (design/validated/serious-games.md §5.6), on the
 * model of `app:help:sync-content`. **Run once after a deploy that adds a game or a template.**.
 *
 * - Each `config/serious_games/{slug}/game.yaml` creates or updates its SeriousGame - title, pitch,
 *   tags, structure. Two things are the administrator's and are written on creation only: the
 *   publication (a game arrives unpublished) and the answer kinds it accepts.
 * - Each `templates/{unit}.json` with no version in the database is installed as **version 1,
 *   validated, « livré avec le jeu »** (no author), its bench report attached: the templates were
 *   validated by the design. A template whose bench is red is refused, never installed.
 * - It **never overwrites** a version created in the database. `--refresh` rewrites a shipped
 *   version only while it is still the unit's current one and has served no question - a question
 *   served by it must stay replayable from it.
 * - A game of the « scénario » mechanic (design/validated/serious-games-neko-con.md §3.1) has no
 *   templates: its `tickets/*.yaml` are read into `scenario.tickets`, checked whole by
 *   ScenarioValidator - a game with a problem is not written - and its acts become the manifest's
 *   `chapters`, a ticket a unit.
 */
#[AsCommand(
    name: 'app:serious-games:sync',
    description: 'Installe les serious games livrés avec le code et leurs gabarits manquants.',
)]
class SeriousGameSyncCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeriousGameRepository $games,
        private readonly SeriousGameTemplateRepository $templates,
        private readonly SeriousGameAnswerRepository $answers,
        private readonly TemplateInterpreter $interpreter,
        #[Autowire('%kernel.project_dir%/config/serious_games')]
        private readonly string $directory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('refresh', null, InputOption::VALUE_NONE, 'Réécrit aussi les versions livrées encore intactes');
        $this->addOption('seeds', null, InputOption::VALUE_REQUIRED, 'Graines du banc passé avant d’installer un gabarit', '200');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montre ce qui serait fait, sans écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $refresh = true === $input->getOption('refresh');
        $dryRun = true === $input->getOption('dry-run');
        $seeds = max(1, (int) $input->getOption('seeds'));
        $bench = new TemplateBench($this->interpreter);
        $refused = 0;

        foreach (glob($this->directory.'/*/game.yaml') ?: [] as $file) {
            /** @var array<string, mixed> $data */
            $data = Yaml::parseFile($file);
            $slug = \is_string($data['slug'] ?? null) ? $data['slug'] : basename(\dirname($file));
            $mechanic = SeriousGameMechanic::tryFrom(self::text($data['mechanic'] ?? 'series')) ?? SeriousGameMechanic::Series;
            if (SeriousGameMechanic::Scenario === $mechanic) {
                $data = ScenarioManifest::withTickets($data, \dirname($file));
                $problems = ScenarioValidator::problems(\is_array($data['scenario'] ?? null) ? $data['scenario'] : []);
                if ([] !== $problems) {
                    $io->error(\sprintf("%s : scénario refusé\n- %s", $slug, implode("\n- ", $problems)));
                    ++$refused;

                    continue;
                }
            }
            $manifest = new SeriousGameManifest($data);

            $game = $this->games->findOneBySlug($slug);
            if (null === $game) {
                $game = new SeriousGame($slug, self::text($data['title'] ?? $slug));
                $game->setPublished(false);
                $game->setAnswerKinds(self::strings($data['answer_kinds'] ?? ['short']));
                $this->entityManager->persist($game);
                $io->text('+ jeu '.$slug);
            } else {
                $io->text('= jeu '.$slug);
            }

            $game->setTitle(self::text($data['title'] ?? $slug))
                ->setPitch(self::text($data['pitch'] ?? ''))
                ->setSubjects(self::strings($data['subjects'] ?? []))
                ->setLevels(self::strings($data['levels'] ?? []))
                ->setUnitLabel(self::text($data['unit_label'] ?? ''))
                ->setUnitsTotal(\count($manifest->units()))
                ->setVersion(self::text($data['version'] ?? '1.0.0'))
                ->setMechanic($mechanic)
                ->setManifest($manifest->toArray());

            if (SeriousGameMechanic::Scenario === $mechanic) {
                $io->text(\sprintf('  scénario : %d tickets', \count($manifest->units())));

                continue;
            }

            foreach ($manifest->unitCodes() as $unit) {
                $path = \dirname($file).'/templates/'.$unit.'.json';
                if (!is_file($path)) {
                    $io->warning(\sprintf('%s : aucun gabarit pour l’unité « %s ».', $slug, $unit));

                    continue;
                }

                try {
                    $definition = TemplateDefinition::fromFile($path);
                    $this->interpreter->validate($definition->document);
                } catch (TemplateFormatException|\JsonException $e) {
                    $io->error(\sprintf('%s/%s : %s', $slug, $unit, $e->getMessage()));
                    ++$refused;

                    continue;
                }

                $versions = null === $game->getId() ? [] : $this->templates->findVersions($game, $unit);
                if ([] !== $versions && !$refresh) {
                    continue;
                }

                $shipped = null;
                if ([] !== $versions) {
                    $current = $versions[0];
                    $untouched = 1 === \count($versions) && $current->isShippedWithTheGame() && $current->isValidated();
                    if (!$untouched || $this->answers->hasServedVersion($game, $unit, $current->getVersion())) {
                        $io->text(\sprintf('  · %s : version livrée modifiée ou déjà servie, laissée telle quelle', $unit));

                        continue;
                    }
                    if ($current->getDefinition() === $definition->document) {
                        continue;
                    }
                    $shipped = $current;
                }

                $report = $bench->run($definition, $seeds);
                if (!$report['green']) {
                    $io->error(\sprintf('%s/%s : banc rouge — %s', $slug, $unit, $report['failures'][0]['detail'] ?? ''));
                    ++$refused;

                    continue;
                }

                if (null === $shipped) {
                    $template = new SeriousGameTemplate($game, $unit, 1, $definition->document, null);
                    $template->markValidated(null);
                    $template->setNote('Livré avec le jeu.');
                    $this->entityManager->persist($template);
                    $io->text('  + '.$unit.' v1');
                } else {
                    $template = $shipped->refreshShippedDefinition($definition->document);
                    $io->text('  ↻ '.$unit.' v1');
                }
                $template->setBenchReport($report);
            }
        }

        if ($dryRun) {
            $io->note('Simulation : rien n’a été écrit.');
        } else {
            $this->entityManager->flush();
        }

        if ($refused > 0) {
            $io->error($refused.' gabarit(s) refusé(s).');

            return Command::FAILURE;
        }

        $io->success('Serious games à jour.');

        return Command::SUCCESS;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return \is_array($value) ? array_values(array_map(self::text(...), $value)) : [];
    }
}
