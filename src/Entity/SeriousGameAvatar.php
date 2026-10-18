<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeriousGameAvatarRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A person's avatar in the serious games (design/validated/serious-games.md §7) - and only there:
 * everywhere else Formatool keeps the photo and the initials.
 *
 * `$config` is the drawing's settings, version 2 (`start: 'a' | 'b'`, no gender stored), read and
 * completed by App\SeriousGame\Avatar\AvatarConfig. It is drawn by one JavaScript module shared by
 * the platform and the games (`public/serious-games/shared/avatar.js`); there is no second
 * implementation in PHP.
 */
#[ORM\Entity(repositoryClass: SeriousGameAvatarRepository::class)]
#[ORM\Table(name: 'serious_game_avatar')]
#[ORM\UniqueConstraint(name: 'uniq_serious_game_avatar_user', columns: ['user_id'])]
class SeriousGameAvatar
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'config', type: Types::JSON)]
    private array $config;

    #[ORM\Column(name: 'config_version', type: Types::SMALLINT)]
    private int $configVersion;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(User $user, array $config, int $configVersion)
    {
        $this->user = $user;
        $this->config = $config;
        $this->configVersion = $configVersion;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        \assert(null !== $this->user);

        return $this->user;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config, int $configVersion): static
    {
        $this->config = $config;
        $this->configVersion = $configVersion;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getConfigVersion(): int
    {
        return $this->configVersion;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
