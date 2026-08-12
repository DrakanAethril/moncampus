<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VideoCuePointRepository;
use App\Util\Timecode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A question embedded in a video: a timecode, plus a QuizQuestion already written in the library
 * (créas 5B). That second half is the whole design - the statement comes from the bank and the
 * verdict from App\Service\QuizAnswerChecker, so the twelve question types are playable inside a
 * video from the first day, zones and appariement and calculée included.
 *
 * Two behaviours per marker, both decided on 2026-08-12 and deliberately asymmetric:
 * $pauseVideo is on (a question nobody has time to read is not a question) and $blocking is off (a
 * wrong answer lets the video run on, correction shown - blocking by default would turn teaching
 * material into an exam).
 */
#[ORM\Entity(repositoryClass: VideoCuePointRepository::class)]
#[ORM\Table(name: 'video_cue_point')]
#[ORM\Index(name: 'idx_video_cue_point_file_timecode', columns: ['file_id', 'timecode_seconds'])]
class VideoCuePoint
{
    /**
     * How far ahead of the question "Revoir le passage" sends the player. Half a minute is what the
     * créa draws, and it is what a passage explaining one point lasts - the button answers a wrong
     * answer with something to watch again, not with a mark.
     */
    public const int REPLAY_LEAD_SECONDS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VideoResourceFile::class, inversedBy: 'cuePoints')]
    #[ORM\JoinColumn(name: 'file_id', nullable: false, onDelete: 'CASCADE')]
    private ?VideoResourceFile $file = null;

    // The question is referenced, never copied: correcting a typo in the library corrects it inside
    // every video that asks it. Deleting it from the library takes its markers with it, which is
    // why this cascades - a marker pointing at nothing has no statement to show.
    #[ORM\ManyToOne(targetEntity: QuizQuestion::class)]
    #[ORM\JoinColumn(name: 'question_id', nullable: false, onDelete: 'CASCADE')]
    private ?QuizQuestion $question = null;

    #[ORM\Column(name: 'timecode_seconds')]
    private int $timecodeSeconds = 0;

    #[ORM\Column(name: 'pause_video', options: ['default' => true])]
    private bool $pauseVideo = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $blocking = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(VideoResourceFile $file, QuizQuestion $question, int $timecodeSeconds)
    {
        $this->file = $file;
        $this->question = $question;
        $this->createdAt = new \DateTimeImmutable();
        $this->setTimecodeSeconds($timecodeSeconds);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFile(): ?VideoResourceFile
    {
        return $this->file;
    }

    public function getQuestion(): ?QuizQuestion
    {
        return $this->question;
    }

    public function setQuestion(QuizQuestion $question): static
    {
        $this->question = $question;

        return $this;
    }

    public function getTimecodeSeconds(): int
    {
        return $this->timecodeSeconds;
    }

    public function setTimecodeSeconds(int $timecodeSeconds): static
    {
        $this->timecodeSeconds = max(0, $timecodeSeconds);

        return $this;
    }

    public function isPauseVideo(): bool
    {
        return $this->pauseVideo;
    }

    public function setPauseVideo(bool $pauseVideo): static
    {
        $this->pauseVideo = $pauseVideo;

        return $this;
    }

    public function isBlocking(): bool
    {
        return $this->blocking;
    }

    public function setBlocking(bool $blocking): static
    {
        $this->blocking = $blocking;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Where "Revoir le passage" starts the player, never before the beginning of the file. */
    public function getReplayFromSeconds(): int
    {
        return max(0, $this->timecodeSeconds - self::REPLAY_LEAD_SECONDS);
    }

    /** "5:40" - the label drawn on the timeline and in every list of markers. */
    public function getFormattedTimecode(): string
    {
        return Timecode::format($this->timecodeSeconds);
    }
}
