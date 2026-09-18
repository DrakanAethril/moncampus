<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VideoWatchProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a student has really watched of one video file, whichever player they used.
 *
 * The exact counterpart of App\Entity\AudioListenProgress, and deliberately a separate table rather
 * than a generalisation of it: the audio module is shipped and referenced by
 * AssignmentNature::Listening, StudentWorkBoard and the mobile API, so merging the two would touch
 * all of that for no user-visible gain. What is shared is the *rule*, restated here in full.
 *
 * $maxWatchedPercent never goes back down: it is the furthest point ever reached, not the one of the
 * latest playback, so replaying or rewinding cannot make a student look like they regressed.
 *
 * The "seeking forward does not count as watched" rule is enforced by the player, not here: only it
 * can tell a position reached by watching from one reached by dragging the scrubber. It credits what
 * it saw play, and a single stored maximum is then enough across sessions - the player picks the
 * crediting back up from the percentage already earned.
 */
#[ORM\Entity(repositoryClass: VideoWatchProgressRepository::class)]
#[ORM\Table(name: 'video_watch_progress')]
#[ORM\UniqueConstraint(name: 'uniq_video_watch_file_student', columns: ['file_id', 'student_id'])]
class VideoWatchProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VideoResourceFile::class)]
    #[ORM\JoinColumn(name: 'file_id', nullable: false, onDelete: 'CASCADE')]
    private ?VideoResourceFile $file = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'max_watched_percent')]
    private int $maxWatchedPercent = 0;

    #[ORM\Column(name: 'last_watched_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastWatchedAt = null;

    #[ORM\Column(name: 'first_watched_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstWatchedAt = null;

    /**
     * When 100 % was first reached - stamped once and never moved, unlike $lastWatchedAt, which a
     * replay a week later pushes forward. Null on the rows completed before the column existed; the
     * readers fall back on $lastWatchedAt there, which is what they read before.
     */
    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * Wall-clock seconds the video actually spent playing, all sessions together - replays and
     * rewinds included. Set against the running time, it says what the percentage cannot: a
     * twelve-minute file « vu à 100 % » in six minutes was played at double speed.
     */
    #[ORM\Column(name: 'watched_seconds')]
    private int $watchedSeconds = 0;

    /** How many VideoWatchEvent skips were recorded - the counter the table reads without the rows. */
    #[ORM\Column(name: 'skip_count')]
    private int $skipCount = 0;

    /** How many times the page lost the focus while the video played, pausing it. */
    #[ORM\Column(name: 'focus_loss_count')]
    private int $focusLossCount = 0;

    public function __construct(VideoResourceFile $file, User $student)
    {
        $this->file = $file;
        $this->student = $student;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFile(): ?VideoResourceFile
    {
        return $this->file;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getMaxWatchedPercent(): int
    {
        return $this->maxWatchedPercent;
    }

    public function isComplete(): bool
    {
        return 100 <= $this->maxWatchedPercent;
    }

    // Only ever ratchets upward - see the class docblock.
    public function registerProgress(int $percent): void
    {
        $now = new \DateTimeImmutable();
        $percent = max(0, min(100, $percent));
        if ($percent > $this->maxWatchedPercent) {
            $this->maxWatchedPercent = $percent;
        }
        if (null === $this->completedAt && $this->isComplete()) {
            $this->completedAt = $now;
        }
        $this->firstWatchedAt ??= $now;
        $this->lastWatchedAt = $now;
    }

    public function getLastWatchedAt(): ?\DateTimeImmutable
    {
        return $this->lastWatchedAt;
    }

    public function getFirstWatchedAt(): ?\DateTimeImmutable
    {
        return $this->firstWatchedAt;
    }

    /** When 100 % was reached, or the last watching for a row completed before that was recorded. */
    public function getCompletedAt(): ?\DateTimeImmutable
    {
        if (!$this->isComplete()) {
            return null;
        }

        return $this->completedAt ?? $this->lastWatchedAt;
    }

    public function getWatchedSeconds(): int
    {
        return $this->watchedSeconds;
    }

    public function addWatchedSeconds(int $seconds): void
    {
        $this->watchedSeconds += max(0, $seconds);
    }

    public function getSkipCount(): int
    {
        return $this->skipCount;
    }

    public function countSkip(): void
    {
        ++$this->skipCount;
    }

    public function getFocusLossCount(): int
    {
        return $this->focusLossCount;
    }

    public function countFocusLoss(): void
    {
        ++$this->focusLossCount;
    }
}
