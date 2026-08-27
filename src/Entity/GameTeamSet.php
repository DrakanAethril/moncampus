<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameTeamSetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The teams a formation plays with: a saved App\Entity\GroupBatch, and nothing more (§4, decision 7).
 *
 * One set per formation, not one per period. The monthly and yearly rankings both read the same
 * teams, and a team that changed underneath a ranking would make the year's own ranking unreadable.
 *
 * A lot **is** the period's teams. `GroupBatch::$groups` is a frozen `list<list<int>>` of student ids
 * taken at save time - deliberately not a relation, so a student joining the class afterwards does
 * not silently change who was in a team.
 *
 * The teams' objective is a **threshold, not a rank**: if every member finishes above the settings'
 * team threshold, each of them gains recognition points. A podium would reward the team that drew
 * the best students - the draw would decide, and nobody could do anything about it. A threshold
 * everyone must clear makes helping the weakest member the rational move, and it is the only place
 * in the whole system that produces mutual aid rather than comparison. There is no first team and no
 * last; several can succeed together.
 */
#[ORM\Entity(repositoryClass: GameTeamSetRepository::class)]
#[ORM\Table(name: 'game_team_set')]
#[ORM\UniqueConstraint(name: 'uniq_game_team_set', columns: ['program_id'])]
class GameTeamSet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Program $program;

    #[ORM\ManyToOne(targetEntity: GroupBatch::class)]
    #[ORM\JoinColumn(name: 'group_batch_id', nullable: false, onDelete: 'CASCADE')]
    private GroupBatch $batch;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Program $program, GroupBatch $batch)
    {
        $this->program = $program;
        $this->batch = $batch;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    public function getBatch(): GroupBatch
    {
        return $this->batch;
    }

    public function setBatch(GroupBatch $batch): static
    {
        $this->batch = $batch;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<list<int>> the frozen membership, one inner list per team */
    public function groups(): array
    {
        return $this->batch->getGroups();
    }
}
