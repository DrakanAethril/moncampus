<?php

declare(strict_types=1);

namespace App\Service\Game;

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
    public function teams(Program $program, \DateTimeImmutable $from, \DateTimeImmutable $to, ?\DateTimeImmutable $now = null): array
    {
        $set = $this->sets->findForProgram($program);

        if (null === $set) {
            return [];
        }

        $threshold = $this->settings->for($program)->getTeamThreshold();
        $students = array_values($program->getStudents()->toArray());
        $standings = $this->reader->standingsFor($students, $program, $from, $to, $now);

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

    public function forStudent(User $student, Program $program, \DateTimeImmutable $from, \DateTimeImmutable $to, ?\DateTimeImmutable $now = null): ?GameTeamView
    {
        foreach ($this->teams($program, $from, $to, $now) as $team) {
            if ($team->contains($student)) {
                return $team;
            }
        }

        return null;
    }

    /** How many teams of the class have cleared the objective - « 3 / 6 », and never a rank. */
    public function reachedCount(Program $program, \DateTimeImmutable $from, \DateTimeImmutable $to, ?\DateTimeImmutable $now = null): int
    {
        return \count(array_filter($this->teams($program, $from, $to, $now), static fn (GameTeamView $team): bool => $team->isReached()));
    }

    /**
     * The teams of a formation ordered by their **mean index** over the window - the team ranking
     * the monthly and yearly screens draw.
     *
     * A mean rather than a total, for exactly the reason the individual ranking is a rate: a team of
     * six would otherwise beat a team of four for being bigger.
     *
     * @return list<array{team: GameTeamView, average: int}>
     */
    public function ranking(Program $program, \DateTimeImmutable $from, \DateTimeImmutable $to, ?\DateTimeImmutable $now = null): array
    {
        $rows = [];

        foreach ($this->teams($program, $from, $to, $now) as $team) {
            $count = \count($team->members);
            $rows[] = [
                'team' => $team,
                'average' => 0 === $count ? 0 : (int) round(array_sum(array_column($team->members, 'index')) / $count),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['average'] <=> $a['average']);

        return $rows;
    }

    /** A lot names itself; its teams are numbered inside it. */
    private function nameOf(string $batchName, int $position): string
    {
        return \sprintf('%s · %d', $batchName, $position + 1);
    }
}
