<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Enum\QuizTournamentStandingBlock;

/** The result of QuizTournamentStanding::compute(): the rows, block by block. */
final readonly class QuizTournamentStandings
{
    /** @param array<string, list<QuizTournamentStandingRow>> $blocks block value => rows, in order */
    public function __construct(private array $blocks)
    {
    }

    /** @return list<QuizTournamentStandingRow> */
    public function rows(QuizTournamentStandingBlock $block): array
    {
        return $this->blocks[$block->value] ?? [];
    }

    /** @return list<QuizTournamentStandingRow> the four blocks one after the other */
    public function all(): array
    {
        $rows = [];
        foreach (QuizTournamentStandingBlock::cases() as $block) {
            array_push($rows, ...$this->rows($block));
        }

        return $rows;
    }

    public function rowFor(int $userId): ?QuizTournamentStandingRow
    {
        foreach ($this->all() as $row) {
            if ($row->entry->userId === $userId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The ranked players' ids and ratings - what isLadderOpponent() reads when a duel or a challenge
     * is stamped.
     *
     * @return list<array{id: int, rating: int}>
     */
    public function rankedRatings(): array
    {
        return array_map(
            static fn (QuizTournamentStandingRow $row): array => ['id' => $row->entry->userId, 'rating' => $row->entry->rating],
            $this->rows(QuizTournamentStandingBlock::Ranked),
        );
    }
}
