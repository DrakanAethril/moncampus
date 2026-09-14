<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JobboardBatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One deposit of offers: either a collecting pass (a token) or a manual import (an administrator).
 *
 * Exactly one of the two origins is set, and both produce the same object with the same tally.
 * That is the point: an import reads back in the history as a pass of the veille, and the ranking
 * rules have a single implementation rather than one per door.
 */
#[ORM\Entity(repositoryClass: JobboardBatchRepository::class)]
#[ORM\Table(name: 'jobboard_batch')]
class JobboardBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Nullable, and kept when the key is later revoked: a batch whose key is gone must still be
    // readable. The filière is copied below for the same reason.
    #[ORM\ManyToOne(targetEntity: JobboardToken::class)]
    #[ORM\JoinColumn(name: 'token_id', nullable: true, onDelete: 'SET NULL')]
    private ?JobboardToken $token = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'imported_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $importedBy = null;

    #[ORM\ManyToOne(targetEntity: Track::class)]
    #[ORM\JoinColumn(name: 'track_id', nullable: false, onDelete: 'CASCADE')]
    private Track $track;

    #[ORM\Column(name: 'opened_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(name: 'created_count')]
    private int $createdCount = 0;

    #[ORM\Column(name: 'reviewed_count')]
    private int $reviewedCount = 0;

    #[ORM\Column(name: 'rejected_count')]
    private int $rejectedCount = 0;

    // Offers this pass reported as gone from the sites. It does not come from `ingest()` like the
    // three above: `POST /offers/close` is a call of its own, made after the batch is closed, and
    // it is filed here rather than in a table of its own because the history reads a pass as one
    // line - an offer that left is the third thing a passage does, next to creating and reviewing.
    #[ORM\Column(name: 'closed_count')]
    private int $closedCount = 0;

    /**
     * Offers this deposit dropped because their site is on the blacklist - accepted at the door,
     * written nowhere.
     *
     * It is a column of its own rather than a share of `rejected_count` because the two answer
     * different questions: a refusal is a defect of the offer, this is a decision of the
     * establishment. Reading them together would make an administrator who has just blacklisted a
     * busy site think the veille had started producing garbage.
     */
    #[ORM\Column(name: 'blocked_count')]
    private int $blockedCount = 0;

    private function __construct(Track $track)
    {
        $this->track = $track;
        $this->openedAt = new \DateTimeImmutable();
    }

    public static function forToken(JobboardToken $token): self
    {
        $batch = new self($token->getTrack());
        $batch->token = $token;

        return $batch;
    }

    public static function forImport(Track $track, ?User $importedBy): self
    {
        $batch = new self($track);
        $batch->importedBy = $importedBy;

        return $batch;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): ?JobboardToken
    {
        return $this->token;
    }

    public function getImportedBy(): ?User
    {
        return $this->importedBy;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function isClosed(): bool
    {
        return null !== $this->closedAt;
    }

    public function close(): static
    {
        $this->closedAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedCount(): int
    {
        return $this->createdCount;
    }

    public function getReviewedCount(): int
    {
        return $this->reviewedCount;
    }

    public function getRejectedCount(): int
    {
        return $this->rejectedCount;
    }

    public function getClosedCount(): int
    {
        return $this->closedCount;
    }

    public function getBlockedCount(): int
    {
        return $this->blockedCount;
    }

    public function tally(int $created, int $reviewed, int $rejected, int $blocked = 0): static
    {
        $this->createdCount += $created;
        $this->reviewedCount += $reviewed;
        $this->rejectedCount += $rejected;
        $this->blockedCount += $blocked;

        return $this;
    }

    /**
     * Added after the fact, and deliberately allowed on a closed batch: the agent's instruction
     * sheet puts `POST /offers/close` at step 6, after the closure. Refusing it there would either
     * lose the figure or force the sheet to be rewritten.
     */
    public function tallyClosed(int $closed): static
    {
        $this->closedCount += $closed;

        return $this;
    }
}
