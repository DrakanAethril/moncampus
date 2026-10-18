<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeriousGameRoleSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One cell of the « Serious games » block of Paramètres › Fonctionnalités
 * (design/validated/serious-games.md §3.1): is this game lit for this role.
 *
 * The same shape as FeatureRoleSetting, one level down: the module itself is
 * `Feature::SeriousGames`, and a game is playable for a role only when both are lit. **By role
 * only**: there is no per-formation setting and no individual derogation for a game, and no
 * teacher ever opens a game to a class.
 *
 * An absent pair is off - a game arrives switched off for everybody but the administrator.
 */
#[ORM\Entity(repositoryClass: SeriousGameRoleSettingRepository::class)]
#[ORM\Table(name: 'serious_game_role_setting')]
#[ORM\UniqueConstraint(name: 'uniq_serious_game_role', columns: ['game_id', 'role'])]
class SeriousGameRoleSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SeriousGame::class)]
    #[ORM\JoinColumn(name: 'game_id', nullable: false, onDelete: 'CASCADE')]
    private ?SeriousGame $game = null;

    #[ORM\Column(name: 'role', length: 40)]
    private string $role;

    #[ORM\Column(name: 'enabled')]
    private bool $enabled;

    public function __construct(SeriousGame $game, string $role, bool $enabled)
    {
        $this->game = $game;
        $this->role = $role;
        $this->enabled = $enabled;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): SeriousGame
    {
        \assert(null !== $this->game);

        return $this->game;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }
}
