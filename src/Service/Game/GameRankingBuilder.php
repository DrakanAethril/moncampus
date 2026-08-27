<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Program;
use App\Entity\User;
use App\Repository\GameAliasRepository;
use App\Repository\GameProfileRepository;

/**
 * The class ranking over a window - a month, or a school year - anonymous, and inside one formation.
 *
 * A student in discreet mode is scored and rewarded like everybody else and simply appears nowhere:
 * they are counted, never named. Leaving takes effect at once; **coming back only takes effect at
 * the next period**, which is what stops the switch from becoming tactical on the eve of a closure.
 */
final class GameRankingBuilder
{
    public function __construct(
        private readonly GameIndexReader $reader,
        private readonly GameAliasRepository $aliases,
        private readonly GameProfileRepository $profiles,
        private readonly GameSettingsProvider $settings,
    ) {
    }

    public function build(Program $program, \DateTimeImmutable $from, \DateTimeImmutable $to, ?User $viewer = null, ?\DateTimeImmutable $now = null): GameRankingView
    {
        $students = array_values($program->getStudents()->toArray());
        $standings = $this->reader->standingsFor($students, $program, $from, $to, $now);
        $aliases = $this->aliases->findForProgram($program);
        $profiles = $this->profiles->findForStudents($students);
        $settings = $this->settings->for($program);

        $listed = [];
        $discreet = 0;

        foreach ($students as $student) {
            $id = (int) $student->getId();

            // A profile row exists only once somebody has earned or asked for something, so the
            // absence of one is the ordinary state and simply means « not discreet ».
            if (isset($profiles[$id]) && $profiles[$id]->isDiscreet()) {
                ++$discreet;

                continue;
            }

            $listed[] = ['student' => $student, 'index' => $standings[$id]->index()];
        }

        usort($listed, static fn (array $a, array $b): int => $b['index'] <=> $a['index']);

        $rows = [];
        $viewerRow = null;
        $rank = 0;
        foreach ($listed as $entry) {
            $id = (int) $entry['student']->getId();
            $isViewer = null !== $viewer && $viewer->getId() === $id;

            $row = new GameRankingRow(
                ++$rank,
                $entry['student'],
                $aliases[$id] ?? null,
                $entry['index'],
                $standings[$id]->tier($settings->getThresholdBronze(), $settings->getThresholdSilver(), $settings->getThresholdGold()),
                $isViewer,
            );

            $rows[] = $row;

            if ($isViewer) {
                $viewerRow = $row;
            }
        }

        return new GameRankingView($rows, $discreet, $viewerRow);
    }
}
