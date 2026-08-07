<?php

namespace App\Entity;

use App\Repository\AudioListenProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a student has really listened to of one file, whichever player they used - web browser or
 * mobile app, both reporting the same events to the same rule (see App\Service\AudioListenTracker).
 *
 * $maxListenedPercent never goes back down: it is the furthest point ever reached, not the one of
 * the latest playback. A replay or a rewind therefore cannot make a student look like they
 * regressed to the teacher, and this value is what decides the completion of a Listening
 * assignment: 100% on each of their files.
 *
 * The "seeking forward does not count as listened" rule, on the other hand, is enforced by the
 * player and not here: only it can tell a position reached by listening from one reached by
 * dragging the scrubber. It credits only what it saw play, and both players - web and mobile -
 * apply that rule before calling. A single stored maximum is then enough, across sessions included:
 * the player picks the crediting back up from the percentage already earned.
 */
#[ORM\Entity(repositoryClass: AudioListenProgressRepository::class)]
#[ORM\Table(name: 'audio_listen_progress')]
#[ORM\UniqueConstraint(name: 'uniq_audio_listen_file_student', columns: ['file_id', 'student_id'])]
class AudioListenProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AudioRecordingFile::class)]
    #[ORM\JoinColumn(name: 'file_id', nullable: false, onDelete: 'CASCADE')]
    private ?AudioRecordingFile $file = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'max_listened_percent')]
    private int $maxListenedPercent = 0;

    #[ORM\Column(name: 'last_listened_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastListenedAt = null;

    public function __construct(AudioRecordingFile $file, User $student)
    {
        $this->file = $file;
        $this->student = $student;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFile(): ?AudioRecordingFile
    {
        return $this->file;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getMaxListenedPercent(): int
    {
        return $this->maxListenedPercent;
    }

    public function isComplete(): bool
    {
        return 100 <= $this->maxListenedPercent;
    }

    // Only ever ratchets upward - see the class docblock.
    public function registerProgress(int $percent): void
    {
        $percent = max(0, min(100, $percent));
        if ($percent > $this->maxListenedPercent) {
            $this->maxListenedPercent = $percent;
        }
        $this->lastListenedAt = new \DateTimeImmutable();
    }

    public function getLastListenedAt(): ?\DateTimeImmutable
    {
        return $this->lastListenedAt;
    }
}
