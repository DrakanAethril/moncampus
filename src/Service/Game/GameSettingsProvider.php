<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\GameProgramSettings;
use App\Entity\Program;
use App\Repository\GameProgramSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The settings of a formation, whether or not it has ever opened the settings screen.
 *
 * for() returns a **transient** row when nothing is stored: a formation that has never been
 * configured plays a complete game on the design's starting values, and reading its settings must
 * not write a row. persistent() is the one the settings form binds to, and it is the only caller
 * that creates.
 */
final class GameSettingsProvider
{
    /** @var array<int, GameProgramSettings> */
    private array $cache = [];

    public function __construct(
        private readonly GameProgramSettingsRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function for(Program $program): GameProgramSettings
    {
        $id = (int) $program->getId();

        return $this->cache[$id] ??= $this->repository->findForProgram($program) ?? new GameProgramSettings($program);
    }

    /** The same row, created and persisted if it did not exist - for the form alone. */
    public function persistent(Program $program): GameProgramSettings
    {
        $settings = $this->repository->findForProgram($program);

        if (null === $settings) {
            $settings = new GameProgramSettings($program);
            $this->entityManager->persist($settings);
            $this->cache[(int) $program->getId()] = $settings;
        }

        return $settings;
    }
}
