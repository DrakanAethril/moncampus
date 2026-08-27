<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\User;

/**
 * One team of a period, and how far each of its members is from the collective threshold.
 *
 * **The lowest member is named and their margin shown**, and that is the most delicate point of the
 * screen: making them anonymous would make the collective objective unplayable - one cannot help
 * somebody one cannot identify. In exchange the screen shows only an index, never the detail of a
 * classmate's families: one sees that somebody is behind, never why.
 */
final readonly class GameTeamView
{
    /** @param list<array{student: User, index: int, above: bool, margin: int}> $members */
    public function __construct(
        public int $position,
        public string $name,
        public array $members,
        public int $threshold,
    ) {
    }

    public function isReached(): bool
    {
        foreach ($this->members as $member) {
            if (!$member['above']) {
                return false;
            }
        }

        return [] !== $this->members;
    }

    public function aboveCount(): int
    {
        return \count(array_filter($this->members, static fn (array $member): bool => $member['above']));
    }

    /** @return array{student: User, index: int, above: bool, margin: int}|null */
    public function lowest(): ?array
    {
        $lowest = null;
        foreach ($this->members as $member) {
            if (null === $lowest || $member['index'] < $lowest['index']) {
                $lowest = $member;
            }
        }

        return $lowest;
    }

    public function contains(User $student): bool
    {
        foreach ($this->members as $member) {
            if ($member['student']->getId() === $student->getId()) {
                return true;
            }
        }

        return false;
    }
}
