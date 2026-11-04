<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\Program;
use App\Entity\User;
use App\Enum\QuizTournamentStandingBlock;

/**
 * One line of a tournament's standings as the page draws it (design/validated/quiz-tournament.md
 * §6.2): the rule's row, the person, « la classe affichée », their victories / draws / defeats and
 * what their rating moved over the last 14 days.
 *
 * @phpstan-import-type UserAvatar from \App\Service\UserAvatars
 */
final readonly class QuizTournamentStandingLine
{
    /** @param UserAvatar $avatar */
    public function __construct(
        public QuizTournamentStandingRow $row,
        public User $user,
        public ?Program $program,
        public int $wins,
        public int $draws,
        public int $losses,
        public int $delta,
        public array $avatar,
    ) {
    }

    public function userId(): int
    {
        return $this->row->entry->userId;
    }

    public function block(): QuizTournamentStandingBlock
    {
        return $this->row->block;
    }

    public function rank(): ?int
    {
        return $this->row->rank;
    }

    public function rating(): int
    {
        return $this->row->entry->rating;
    }

    public function duels(): int
    {
        return $this->row->entry->duels;
    }

    public function eligible(): bool
    {
        return $this->row->entry->eligible;
    }

    /** The placement duels still to play before the player may be ranked, 0 once out of placement. */
    public function missingPlacementDuels(): int
    {
        return max(0, QuizTournamentStanding::PLACEMENT_DUELS - $this->row->entry->duels);
    }
}
