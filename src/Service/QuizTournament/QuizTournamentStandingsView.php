<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Enum\QuizTournamentStandingBlock;

/**
 * A tournament's standings, assembled for the page (QuizTournamentStandingsAssembler): the lines of
 * the four blocks in order, the podium, and the moment they were read at - now, or the tournament's
 * end once it is over (frozen standings).
 */
final readonly class QuizTournamentStandingsView
{
    public const int PODIUM = 3;

    /**
     * @param array<string, list<QuizTournamentStandingLine>> $blocks block value => lines, in order
     */
    public function __construct(
        public QuizTournamentStandings $standings,
        private array $blocks,
        public \DateTimeImmutable $moment,
    ) {
    }

    /** @return list<QuizTournamentStandingLine> */
    public function lines(QuizTournamentStandingBlock $block): array
    {
        return $this->blocks[$block->value] ?? [];
    }

    public function count(QuizTournamentStandingBlock $block): int
    {
        return \count($this->lines($block));
    }

    /** @return list<QuizTournamentStandingLine> the first three ranked, best first */
    public function podium(): array
    {
        return \array_slice($this->lines(QuizTournamentStandingBlock::Ranked), 0, self::PODIUM);
    }

    public function lineFor(?int $userId): ?QuizTournamentStandingLine
    {
        if (null === $userId) {
            return null;
        }

        foreach ($this->blocks as $lines) {
            foreach ($lines as $line) {
                if ($line->userId() === $userId) {
                    return $line;
                }
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        foreach ($this->blocks as $lines) {
            if ([] !== $lines) {
                return false;
            }
        }

        return true;
    }
}
