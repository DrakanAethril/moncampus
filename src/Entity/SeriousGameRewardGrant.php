<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SeriousGameFinish;
use App\Repository\SeriousGameRewardGrantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A collection piece a student holds (design/validated/serious-games.md §8, « Récompenses »): the
 * Astrolabe, a constellation card, the Étoile pin.
 *
 * **Students only** - an adult plays and keeps their progress, but collects nothing. No point, no
 * ranking, no link with the Jeu du campus. The finish (brass, silver, gold - null for a piece that
 * has none) **only goes up**: raiseFinish() refuses to lower it.
 */
#[ORM\Entity(repositoryClass: SeriousGameRewardGrantRepository::class)]
#[ORM\Table(name: 'serious_game_reward_grant')]
#[ORM\UniqueConstraint(name: 'uniq_serious_game_reward_grant', columns: ['student_id', 'reward_code'])]
class SeriousGameRewardGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'reward_code', length: 80)]
    private string $rewardCode;

    #[ORM\Column(name: 'finish', length: 20, nullable: true, enumType: SeriousGameFinish::class)]
    private ?SeriousGameFinish $finish = null;

    #[ORM\Column(name: 'granted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $grantedAt;

    #[ORM\Column(name: 'finish_raised_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishRaisedAt = null;

    public function __construct(User $student, string $rewardCode, ?SeriousGameFinish $finish = null)
    {
        $this->student = $student;
        $this->rewardCode = $rewardCode;
        $this->finish = $finish;
        $this->grantedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): User
    {
        \assert(null !== $this->student);

        return $this->student;
    }

    public function getRewardCode(): string
    {
        return $this->rewardCode;
    }

    public function getFinish(): ?SeriousGameFinish
    {
        return $this->finish;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function getFinishRaisedAt(): ?\DateTimeImmutable
    {
        return $this->finishRaisedAt;
    }

    /** @return bool whether the finish actually went up */
    public function raiseFinish(SeriousGameFinish $finish): bool
    {
        if (null !== $this->finish && $this->finish->rank() >= $finish->rank()) {
            return false;
        }

        $this->finish = $finish;
        $this->finishRaisedAt = new \DateTimeImmutable();

        return true;
    }
}
