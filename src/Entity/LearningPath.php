<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LearningPathStatus;
use App\Repository\LearningPathRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A learning path: an ordered sequence of its author's online courses, with a validation quiz
 * wherever the author put one (design/validated/cours-en-ligne.md, §10).
 *
 * **Nothing of a path is read without an account** - not its plan, not its title. That is why it
 * carries no public address: it is reached by its id, under `/paths`, behind the sign-in. The
 * courses it lines up may themselves be public; the path is what asks for an account.
 *
 * Like a course it is its author's alone. Who has started it, and how far they are, is read by its
 * author and by nobody else (App\Security\Voter\LearningPathVoter::TRACK).
 */
#[ORM\Entity(repositoryClass: LearningPathRepository::class)]
#[ORM\Table(name: 'learning_path')]
#[ORM\Index(name: 'idx_learning_path_status', columns: ['status'])]
class LearningPath
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'learningPathTitleRequiredMessage')]
    #[Assert\Length(max: 200)]
    private string $title;

    #[ORM\Column(length: 300)]
    #[Assert\Length(max: 300)]
    private string $summary = '';

    /** Sanitized HTML. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: LearningPathStatus::class)]
    private LearningPathStatus $status = LearningPathStatus::Draft;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** @var Collection<int, LearningPathStep> */
    #[ORM\OneToMany(mappedBy: 'path', targetEntity: LearningPathStep::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $steps;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $title)
    {
        $this->owner = $owner;
        $this->title = $title;
        $this->steps = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        \assert(null !== $this->owner);

        return $this->owner;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner === $user;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = trim((string) $summary);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = null === $description || '' === trim($description) ? null : $description;

        return $this;
    }

    public function getStatus(): LearningPathStatus
    {
        return $this->status;
    }

    /** Goes through App\Service\LearningPath\LearningPathWriter, which holds what publishing asks for. */
    public function setStatus(LearningPathStatus $status): static
    {
        $this->status = $status;
        if (LearningPathStatus::Published === $status && null === $this->publishedAt) {
            $this->publishedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function isPublished(): bool
    {
        return LearningPathStatus::Published === $this->status;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /** @return Collection<int, LearningPathStep> */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    /**
     * The steps in the order they are followed, whatever order the collection was filled in.
     *
     * @return list<LearningPathStep>
     */
    public function orderedSteps(): array
    {
        $steps = $this->steps->toArray();
        usort($steps, static fn (LearningPathStep $a, LearningPathStep $b): int => [$a->getPosition(), (int) $a->getId()] <=> [$b->getPosition(), (int) $b->getId()]);

        return $steps;
    }

    public function addStep(LearningPathStep $step): static
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
        }

        return $this;
    }

    public function removeStep(LearningPathStep $step): static
    {
        $this->steps->removeElement($step);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
