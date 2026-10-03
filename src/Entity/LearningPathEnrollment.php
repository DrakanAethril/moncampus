<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LearningPathEnrollmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Somebody following a learning path (design/validated/cours-en-ligne.md, §10): written when they
 * press « Commencer le parcours », which is what gives the follow-up its start date.
 *
 * It carries three dates and no progress: how far the person is is *read*, from the steps they
 * opened and the quizzes they validated (App\Service\LearningPath\LearningPathRule), so that
 * changing a threshold changes the reading rather than leaving a stored figure behind.
 * `$completedAt` is the one thing written about progress, and it is written once: the day
 * everything was done.
 *
 * These rows are personal data kept for a stated time - 24 months after the last activity
 * (App\Command\PurgePlatformActivityCommand).
 */
#[ORM\Entity(repositoryClass: LearningPathEnrollmentRepository::class)]
#[ORM\Table(name: 'learning_path_enrollment')]
#[ORM\UniqueConstraint(name: 'uniq_learning_path_enrollment', columns: ['path_id', 'user_id'])]
#[ORM\Index(name: 'idx_learning_path_enrollment_activity', columns: ['last_activity_at'])]
class LearningPathEnrollment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LearningPath::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LearningPath $path = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastActivityAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(LearningPath $path, User $user)
    {
        $this->path = $path;
        $this->user = $user;
        $this->startedAt = new \DateTimeImmutable();
        $this->lastActivityAt = $this->startedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): LearningPath
    {
        \assert(null !== $this->path);

        return $this->path;
    }

    public function getUser(): User
    {
        \assert(null !== $this->user);

        return $this->user;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getLastActivityAt(): \DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function touch(): static
    {
        $this->lastActivityAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /** Written the first time everything is done, and never moved afterwards. */
    public function markCompleted(): static
    {
        $this->completedAt ??= new \DateTimeImmutable();

        return $this;
    }
}
