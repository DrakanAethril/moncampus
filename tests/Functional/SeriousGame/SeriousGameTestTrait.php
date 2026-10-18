<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameRoleSetting;
use App\Entity\SeriousGameTemplate;
use App\SeriousGame\SeriousGamePlay;
use App\SeriousGame\Template\TemplateDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * A game installed as `app:serious-games:sync` installs it - the manifest of game.yaml, and the
 * shipped templates of the units a test needs, validated, version 1 - without running the bench.
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
            ->setManifest($manifest);
        $entityManager->persist($game);
        foreach ($roles as $role) {
            $entityManager->persist(new SeriousGameRoleSetting($game, $role, true));
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
}
