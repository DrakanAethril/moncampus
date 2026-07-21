<?php

namespace App\Entity;

use App\Repository\GradeAudioCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An optional teacher audio appreciation for one Grade (design's Part C - recorded via
 * MediaRecorder, Opus/WebM ~24kbps mono, uploaded straight to S3 via a presigned PUT URL at
 * $s3Key = "audio-appreciations/{evaluation_id}/{student_id}.webm" -
 * App\Service\GradeAudioCommentUploadService). $maxListenedPercent only ever increases (design's
 * "Non écoutée / Écoutée X% / Écoutée") - it's the student's furthest point reached across every
 * playback, not the latest one, so a listen that's replayed or scrubbed back never looks like it
 * regressed to the teacher.
 */
#[ORM\Entity(repositoryClass: GradeAudioCommentRepository::class)]
#[ORM\Table(name: 'grade_audio_comment')]
class GradeAudioComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Grade::class, inversedBy: 'audioComment')]
    #[ORM\JoinColumn(name: 'grade_id', nullable: false, unique: true)]
    private ?Grade $grade = null;

    #[ORM\Column(name: 's3_key', length: 255)]
    private string $s3Key;

    #[ORM\Column(name: 'file_size')]
    private int $fileSize = 0;

    #[ORM\Column(name: 'recorded_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recorded_by_id', nullable: false)]
    private ?User $recordedBy = null;

    #[ORM\Column(name: 'max_listened_percent')]
    private int $maxListenedPercent = 0;

    #[ORM\Column(name: 'last_listened_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastListenedAt = null;

    public function __construct(Grade $grade, string $s3Key, int $fileSize, User $recordedBy)
    {
        $this->grade = $grade;
        $this->s3Key = $s3Key;
        $this->fileSize = $fileSize;
        $this->recordedBy = $recordedBy;
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGrade(): ?Grade
    {
        return $this->grade;
    }

    public function getS3Key(): string
    {
        return $this->s3Key;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }

    public function getMaxListenedPercent(): int
    {
        return $this->maxListenedPercent;
    }

    // Only ever ratchets upward - see class docblock.
    public function registerListenProgress(int $percent): void
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
