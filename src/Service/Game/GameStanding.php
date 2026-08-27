<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Program;
use App\Entity\User;

/**
 * Where one student stands on one period, with everything a screen needs to make the number
 * checkable: the index, each family's rate, its numerator **and its denominator**.
 *
 * The denominator is not decoration. « 82 % » next to « 9 semaines relevées » can be verified by the
 * person reading it; « 82 % » on its own has to be believed. Screen 1 prints both for that reason,
 * and this object is why it can.
 */
final readonly class GameStanding
{
    /**
     * @param array<string, int>  $earned   family value => points, from the ledger
     * @param array<string, ?int> $possible family value => denominator, null when the family has no data
     */
    public function __construct(
        public User $student,
        public Program $program,
        public GameScore $score,
        public array $earned,
        public array $possible,
    ) {
    }

    public function index(): int
    {
        return $this->score->index;
    }

    public function earnedIn(string $family): int
    {
        return max(0, $this->earned[$family] ?? 0);
    }

    public function possibleIn(string $family): ?int
    {
        return $this->possible[$family] ?? null;
    }

    /** The tier reached, given this formation's thresholds - never a total, always the index. */
    public function tier(int $bronze, int $silver, int $gold): ?string
    {
        return match (true) {
            $this->index() >= $gold => 'gold',
            $this->index() >= $silver => 'silver',
            $this->index() >= $bronze => 'bronze',
            default => null,
        };
    }
}
