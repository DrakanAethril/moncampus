<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RewardNature;
use App\Enum\RewardScope;
use App\Repository\RewardItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One entry of a formation's reward catalogue (§5.5).
 *
 * The six level frames and the promotion trophy are **entries of this same catalogue**, granted by
 * the machine rather than by a hand. There is no separate mechanism for them, which is what stops
 * the automatic rewards and the hand-granted ones from drifting into two different ideas of what a
 * reward is.
 */
#[ORM\Entity(repositoryClass: RewardItemRepository::class)]
#[ORM\Table(name: 'reward_item')]
#[ORM\Index(name: 'idx_reward_item_program', columns: ['program_id'])]
class RewardItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null on the establishment-wide entries the four tiers are - they exist for every formation. */
    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Program $program = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $label = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: RewardNature::class)]
    private RewardNature $nature = RewardNature::Symbolic;

    #[ORM\Column(length: 20, enumType: RewardScope::class)]
    private RewardScope $scope = RewardScope::Student;

    /** One or two characters - the catalogue is drawn with emoji rather than with an asset. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $icon = null;

    /** How many exist at all - a place on an outing; null is unlimited. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 10000)]
    private ?int $quantity = null;

    #[ORM\Column]
    private bool $active = true;

    /** The tier this entry stands for, when it is one - `trophy` today, and nothing else. */
    #[ORM\Column(name: 'tier_code', length: 20, nullable: true)]
    private ?string $tierCode = null;

    /**
     * The level this entry is the frame of, 1 to 6, or null when it is not a frame.
     *
     * **One frame per level, and there are six.** There used to be three - bronze, silver, gold -
     * granted on the *index* of a period, which sat next to six levels granted on points and read as
     * an arithmetic mistake to anybody looking at both. The frames now follow the levels one for
     * one, and the index's own tiers stay what they always were underneath: a badge on the ranking,
     * computed from the thresholds, never an object in the catalogue.
     */
    #[ORM\Column(name: 'level', nullable: true)]
    #[Assert\Range(min: 1, max: 6)]
    private ?int $level = null;

    public function __construct(?Program $program, string $label, RewardNature $nature, RewardScope $scope = RewardScope::Student)
    {
        $this->program = $program;
        $this->label = $label;
        $this->nature = $nature;
        $this->scope = $scope;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = '' === $description ? null : $description;

        return $this;
    }

    public function getNature(): RewardNature
    {
        return $this->nature;
    }

    public function setNature(RewardNature $nature): static
    {
        $this->nature = $nature;

        return $this;
    }

    public function getScope(): RewardScope
    {
        return $this->scope;
    }

    public function setScope(RewardScope $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = '' === $icon ? null : $icon;

        return $this;
    }

    public function getLevel(): ?int
    {
        return $this->level;
    }

    public function setLevel(?int $level): static
    {
        $this->level = $level;

        return $this;
    }

    /** Granted by the machine - a level frame, or the promotion trophy - rather than by a hand. */
    public function isAutomatic(): bool
    {
        return null !== $this->level || null !== $this->tierCode;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getTierCode(): ?string
    {
        return $this->tierCode;
    }

    public function setTierCode(?string $tierCode): static
    {
        $this->tierCode = $tierCode;

        return $this;
    }
}
