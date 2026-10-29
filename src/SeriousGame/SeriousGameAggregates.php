<?php

declare(strict_types=1);

namespace App\SeriousGame;

use App\Entity\Program;
use App\Entity\SeriousGame;
use App\Entity\SeriousGameSession;
use App\Entity\User;
use App\Enum\SeriousGameSessionMode;
use App\Repository\SeriousGameSessionRepository;
use App\Repository\SeriousGameUnitStateRepository;

/**
 * The staff's reading (design/validated/serious-games.md §1.9): figures of the whole, **no name** -
 * per class, how many students played, the median of lit units, the series of the last seven days.
 * Read by staff, staff-lead and administrators (SeriousGameVoter::READ_AGGREGATE).
 */
final readonly class SeriousGameAggregates
{
    public function __construct(
        private SeriousGameUnitStateRepository $states,
        private SeriousGameSessionRepository $sessions,
    ) {
    }

    /**
     * @param list<Program> $programs
     *
     * @return list<array{program: Program, students: int, players: int, medianLit: int, seriesThisWeek: int, finals: int}>
     */
    public function perClass(SeriousGame $game, array $programs): array
    {
        // A ticket of a scenario counts once closed, whatever its stars (1 to 3).
        $litAt = $game->isScenario() ? 1 : SeriousGamePlay::manifest($game)->litAt();
        $weekAgo = new \DateTimeImmutable('-7 days');
        $rows = [];
        foreach ($programs as $program) {
            /** @var list<User> $students */
            $students = array_values($program->getStudents()->toArray());
            $states = $this->states->findForPlayers($students, $game);
            $sessions = $this->sessions->findEndedForPlayers($students, $game);
            $lit = [];
            foreach ($students as $student) {
                $lit[] = \count(array_filter($states[(int) $student->getId()] ?? [], static fn ($s): bool => ($s->getBest() ?? 0) >= $litAt));
            }
            sort($lit);
            $all = array_merge(...array_values($sessions) ?: [[]]);
            $rows[] = [
                'program' => $program,
                'students' => \count($students),
                'players' => \count($sessions),
                'medianLit' => [] === $lit ? 0 : $lit[intdiv(\count($lit), 2)],
                'seriesThisWeek' => \count(array_filter($all, static fn (SeriousGameSession $s): bool => \in_array($s->getMode(), [SeriousGameSessionMode::Series, SeriousGameSessionMode::Ticket], true) && null !== $s->getScore() && $s->getEndedAt() >= $weekAgo)),
                'finals' => \count(array_filter($all, static fn (SeriousGameSession $s): bool => $s->isFinal())),
            ];
        }

        return $rows;
    }
}
