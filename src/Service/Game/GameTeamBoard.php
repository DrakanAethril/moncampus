<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\GameTeamSetRepository;
use App\Repository\UserRepository;

/**
 * The period's teams, read off the saved lot (§4, decision 7).
 *
 * `GroupBatch::$groups` is a frozen `list<list<int>>` of student ids - not a relation - so a team
 * keeps the members it was drawn with even if the class changes afterwards. Membership is therefore
 * resolved here by id, and a student who has left simply drops out of the list rather than making
 * the team unreadable.
 */
final class GameTeamBoard
{
    public function __construct(
        private readonly GameTeamSetRepository $sets,
        private readonly GameIndexReader $reader,
        private readonly GameSettingsProvider $settings,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return list<GameTeamView>
     */
    public function teams(Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $now = null): array
    {
        $set = $this->sets->findForPeriod($program, $period);

        if (null === $set) {
            return [];
        }

        $threshold = $this->settings->for($program)->getTeamThreshold();
        $students = array_values($program->getStudents()->toArray());
        $standings = $this->reader->standingsFor($students, $program, $period, $now);

        $byId = [];
        foreach ($students as $student) {
            $byId[(int) $student->getId()] = $student;
        }

        $teams = [];
        foreach ($set->groups() as $position => $ids) {
            $members = [];
            foreach ($ids as $id) {
                $student = $byId[$id] ?? $this->users->find($id);

                if (!$student instanceof User) {
                    continue;
                }

                // A lot is frozen, so it may name somebody who has since left the formation: they
                // have no standing, and they read as zero rather than breaking the team's line.
                $index = isset($standings[$id]) ? $standings[$id]->index() : 0;
                $members[] = [
                    'student' => $student,
                    'index' => $index,
                    'above' => $index >= $threshold,
                    'margin' => max(0, $threshold - $index),
                ];
            }

            $teams[] = new GameTeamView($position + 1, $this->nameOf($set->getBatch()->getName(), $position), $members, $threshold);
        }

        return $teams;
    }

    public function forStudent(User $student, Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $now = null): ?GameTeamView
    {
        foreach ($this->teams($program, $period, $now) as $team) {
            if ($team->contains($student)) {
                return $team;
            }
        }

        return null;
    }

    /** How many teams of the class have cleared the objective - « 3 / 6 », and never a rank. */
    public function reachedCount(Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $now = null): int
    {
        return \count(array_filter($this->teams($program, $period, $now), static fn (GameTeamView $team): bool => $team->isReached()));
    }

    /** A lot names itself; its teams are numbered inside it. */
    private function nameOf(string $batchName, int $position): string
    {
        return \sprintf('%s · %d', $batchName, $position + 1);
    }
}
