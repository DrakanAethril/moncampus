<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\JobboardSource;
use App\Repository\JobboardCursorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where the veille stopped last time, for one (filière, site, search).
 *
 * The agent reads these **before** collecting and writes them **after** closing its batch, never
 * during: a cursor advanced in the middle of a pass that then dies would skip, for ever, the offers
 * it had not yet filed.
 *
 * `search` is the agent's own identifier for one search - a saved query, a URL, a label. The
 * platform never interprets it; it only has to be stable from one pass to the next.
 */
#[ORM\Entity(repositoryClass: JobboardCursorRepository::class)]
#[ORM\Table(name: 'jobboard_cursor')]
#[ORM\UniqueConstraint(name: 'uniq_jobboard_cursor_scope', columns: ['track_id', 'source', 'search'])]
class JobboardCursor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Track::class)]
    #[ORM\JoinColumn(name: 'track_id', nullable: false, onDelete: 'CASCADE')]
    private Track $track;

    #[ORM\Column(length: 32, enumType: JobboardSource::class)]
    private JobboardSource $source;

    #[ORM\Column(length: 190)]
    private string $search;

    #[ORM\Column(name: 'last_ref', length: 190, nullable: true)]
    private ?string $lastRef = null;

    #[ORM\Column(name: 'last_published_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastPublishedAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Track $track, JobboardSource $source, string $search)
    {
        $this->track = $track;
        $this->source = $source;
        $this->search = $search;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getSource(): JobboardSource
    {
        return $this->source;
    }

    public function getSearch(): string
    {
        return $this->search;
    }

    public function getLastRef(): ?string
    {
        return $this->lastRef;
    }

    public function getLastPublishedAt(): ?\DateTimeImmutable
    {
        return $this->lastPublishedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function moveTo(?string $lastRef, ?\DateTimeImmutable $lastPublishedAt): static
    {
        $this->lastRef = $lastRef;
        $this->lastPublishedAt = $lastPublishedAt;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
