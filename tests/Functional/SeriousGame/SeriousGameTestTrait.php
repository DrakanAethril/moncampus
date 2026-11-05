<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\Section;
use App\Entity\SeriousGame;
use App\Entity\SeriousGameRoleSetting;
use App\Entity\SeriousGameTemplate;
use App\Entity\User;
use App\Enum\SeriousGameMechanic;
use App\Repository\ProgramRepository;
use App\SeriousGame\Scenario\ScenarioManifest;
use App\SeriousGame\SeriousGamePlay;
use App\SeriousGame\SeriousGameScenarioPlay;
use App\SeriousGame\Template\TemplateDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * A game installed as `app:serious-games:sync` installs it - the manifest of game.yaml, and the
 * shipped templates of the units a test needs, validated, version 1 - without running the bench.
 *
 * `ROLE_STUDENT` among the roles opens the game to students the way the migration of the students'
 * matrix cell did (design/validated/quiz-tournament.md §14): targeted on every Section, every
 * student already there enrolled in an active formation (one is created for those who have none).
 * A student created afterwards is reached only once enrolled under a Section - see
 * openToStudents().
 */
trait SeriousGameTestTrait
{
    /**
     * @param list<string> $roles the roles the game is lit for
     * @param list<string> $units the units installed (all of them when empty)
     */
    private function installObservatoire(array $roles = ['ROLE_STUDENT', 'ROLE_TEACHER'], array $units = [], bool $published = true): SeriousGame
    {
        return $this->installGame('observatoire', $roles, $units, $published);
    }

    /**
     * @param list<string> $roles the roles the game is lit for
     * @param list<string> $units the units installed (all of them when empty)
     */
    private function installGame(string $slug, array $roles = ['ROLE_STUDENT', 'ROLE_TEACHER'], array $units = [], bool $published = true): SeriousGame
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $directory = static::getContainer()->getParameter('kernel.project_dir').'/config/serious_games/'.$slug;
        /** @var array<string, mixed> $manifest */
        $manifest = Yaml::parseFile($directory.'/game.yaml');
        $scenario = 'scenario' === ($manifest['mechanic'] ?? null);
        if ($scenario) {
            $manifest = ScenarioManifest::withTickets($manifest, $directory);
        }
        $title = \is_string($manifest['title'] ?? null) ? $manifest['title'] : $slug;
        $unitLabel = \is_string($manifest['unit_label'] ?? null) ? $manifest['unit_label'] : '';
        $answerKinds = array_values(array_filter(\is_array($manifest['answer_kinds'] ?? null) ? $manifest['answer_kinds'] : ['short'], is_string(...)));

        $game = (new SeriousGame($slug, $title))
            ->setPublished($published)
            ->setUnitsTotal(\count(SeriousGamePlay::manifest((new SeriousGame($slug, $title))->setManifest($manifest))->units()))
            ->setUnitLabel($unitLabel)
            ->setAnswerKinds($answerKinds)
            ->setSubjects(array_values(array_filter(\is_array($manifest['subjects'] ?? null) ? $manifest['subjects'] : [], is_string(...))))
            ->setLevels(array_values(array_filter(\is_array($manifest['levels'] ?? null) ? $manifest['levels'] : [], is_string(...))))
            ->setMechanic($scenario ? SeriousGameMechanic::Scenario : SeriousGameMechanic::Series)
            ->setManifest($manifest);
        $entityManager->persist($game);
        foreach ($roles as $role) {
            // The students' cell left the matrix: they are let in by the targeting.
            if ('ROLE_STUDENT' !== $role) {
                $entityManager->persist(new SeriousGameRoleSetting($game, $role, true));
            }
        }
        if (\in_array('ROLE_STUDENT', $roles, true)) {
            $this->openToStudents($game);
        }

        foreach (glob($directory.'/templates/*.json') ?: [] as $file) {
            $definition = TemplateDefinition::fromFile($file);
            if ([] !== $units && !\in_array($definition->unit, $units, true)) {
                continue;
            }
            $template = new SeriousGameTemplate($game, $definition->unit, 1, $definition->document, null);
            $template->markValidated(null);
            $template->setBenchReport(['green' => true, 'ranAt' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'questions' => 0, 'recognised' => 0, 'wrongs' => 0, 'maxAttempts' => 0, 'failures' => [], 'neverSeen' => []]);
            $entityManager->persist($template);
        }
        $entityManager->flush();

        return $game;
    }

    /**
     * Opens the game to every student there is: those enrolled in no active formation get one, and
     * the game is targeted on every Section.
     */
    private function openToStudents(SeriousGame $game): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $programs = static::getContainer()->get(ProgramRepository::class);
        $loose = array_values(array_filter(
            $entityManager->getRepository(User::class)->findAll(),
            static fn (User $user): bool => \in_array('ROLE_STUDENT', $user->getRoles(), true) && [] === $programs->findAllActiveForStudent($user),
        ));
        if ([] !== $loose) {
            $this->createProgram($loose, [], $this->fixtureAuthor());
        }

        foreach ($entityManager->getRepository(Section::class)->findAll() as $section) {
            $game->addTargetSection($section);
        }
        $entityManager->flush();
    }

    private function fixtureAuthor(): User
    {
        $existing = static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['username' => 'fixture.author']);

        return $existing ?? $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'fixture.author');
    }

    /**
     * The position the game sends for the option written at $written: a ticket's session shows a
     * step's options in an order of its own (ScenarioRules::optionOrder()), read here off the
     * session's opening, as the game reads it.
     *
     * @param array<array-key, mixed> $start the answer to `POST tickets`
     */
    private function shown(SeriousGame $game, array $start, string $stepId, int $written): int
    {
        $ticket = $start['ticket'] ?? null;
        self::assertIsArray($ticket);
        $id = $ticket['id'] ?? null;
        self::assertIsString($id);
        $definition = SeriousGameScenarioPlay::scenario($game)->ticket($id);
        $step = $definition?->step($stepId) ?? $definition?->guideStep($stepId);
        self::assertNotNull($step, $id.' › '.$stepId);
        $text = $step->options[$written]['text'] ?? self::fail('no option '.$written.' in '.$stepId);

        $steps = \is_array($ticket['steps'] ?? null) ? $ticket['steps'] : [];
        $guide = \is_array($start['guide'] ?? null) ? $start['guide'] : [];
        foreach ([...$steps, ...$guide] as $view) {
            if (\is_array($view) && $stepId === ($view['id'] ?? null) && \is_array($view['options'] ?? null)) {
                $position = array_search($text, $view['options'], true);
                self::assertIsInt($position);

                return $position;
            }
        }

        return self::fail($stepId.' is not shown');
    }
}
