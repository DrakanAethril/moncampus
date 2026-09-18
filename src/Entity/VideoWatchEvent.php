<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VideoWatchEventType;
use App\Repository\VideoWatchEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One skip or one loss of focus during a student's watching of one video file - the detail behind
 * the two counters VideoWatchProgress keeps.
 *
 * Append-only and never read by the completion rule: whether a travail is done is the percentage's
 * business alone, which the player only raises for what it saw play. These rows are what lets the
 * teacher see *how* the video was watched - « a sauté de 2:10 à 6:45, puis est revenu » - which a
 * single percentage cannot say.
 *
 * Positions are in seconds of the file, whole seconds: the statistics print mm:ss.
 */
#[ORM\Entity(repositoryClass: VideoWatchEventRepository::class)]
#[ORM\Table(name: 'video_watch_event')]
class VideoWatchEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VideoWatchProgress::class)]
    #[ORM\JoinColumn(name: 'progress_id', nullable: false, onDelete: 'CASCADE')]
    private ?VideoWatchProgress $progress = null;

    #[ORM\Column(length: 20, enumType: VideoWatchEventType::class)]
    private ?VideoWatchEventType $type = null;

    /** Where the playhead was: the start of a skip, or where the video was paused on losing focus. */
    #[ORM\Column(name: 'position_seconds')]
    private int $positionSeconds = 0;

    /** Where a skip landed. Null for a loss of focus. */
    #[ORM\Column(name: 'target_seconds', nullable: true)]
    private ?int $targetSeconds = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    public function __construct(VideoWatchProgress $progress, VideoWatchEventType $type, int $positionSeconds, ?int $targetSeconds = null)
    {
        $this->progress = $progress;
        $this->type = $type;
        $this->positionSeconds = $positionSeconds;
        $this->targetSeconds = $targetSeconds;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgress(): ?VideoWatchProgress
    {
        return $this->progress;
    }

    public function getType(): ?VideoWatchEventType
    {
        return $this->type;
    }

    public function isSkip(): bool
    {
        return VideoWatchEventType::Skip === $this->type;
    }

    public function getPositionSeconds(): int
    {
        return $this->positionSeconds;
    }

    public function getTargetSeconds(): ?int
    {
        return $this->targetSeconds;
    }

    /** How much of the file the skip jumped over. Zero for a loss of focus. */
    public function getSkippedSeconds(): int
    {
        return null === $this->targetSeconds ? 0 : max(0, $this->targetSeconds - $this->positionSeconds);
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
