<?php

namespace App\Entity;

use App\Repository\AudioRecordingFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One audio file of an AudioRecording: either common to the whole audience ($student null), or one
 * given student's. A single recording may carry both.
 *
 * The file itself lives in the bucket under $storageKey, written by App\Service\AudioUploadService
 * - same format and same chain as the gradebook's former audio comments, whose recorder this tool
 * inherits.
 */
#[ORM\Entity(repositoryClass: AudioRecordingFileRepository::class)]
#[ORM\Table(name: 'audio_recording_file')]
class AudioRecordingFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AudioRecording::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'recording_id', nullable: false, onDelete: 'CASCADE')]
    private ?AudioRecording $recording = null;

    // Null = common file. An individual file always names a student of the audience.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'storage_key', length: 255)]
    private string $storageKey;

    // What a common file's row shows next to its name ("Consignes générales.mp3 0:52"). Measured in
    // the browser as the recording is made: MediaRecorder writes no usable duration into the
    // container it produces, and reading it back server-side would mean decoding the audio for the
    // sake of a label.
    #[ORM\Column(name: 'duration_seconds')]
    private int $durationSeconds = 0;

    #[ORM\Column(name: 'file_size')]
    private int $fileSize = 0;

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName = '';

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(name: 'recorded_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recorded_by_id', nullable: false)]
    private ?User $recordedBy = null;

    public function __construct(AudioRecording $recording, string $storageKey, User $recordedBy, ?User $student = null)
    {
        $this->recording = $recording;
        $this->storageKey = $storageKey;
        $this->recordedBy = $recordedBy;
        $this->student = $student;
        $this->position = $recording->nextPosition();
        $this->recordedAt = new \DateTimeImmutable();
        $recording->addFile($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecording(): ?AudioRecording
    {
        return $this->recording;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function isIndividual(): bool
    {
        return null !== $this->student;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(int $durationSeconds): static
    {
        $this->durationSeconds = max(0, $durationSeconds);

        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): static
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }

    /** "2:10" - the duration as the file list and the statistics write it. */
    public function getFormattedDuration(): string
    {
        return sprintf('%d:%02d', intdiv($this->durationSeconds, 60), $this->durationSeconds % 60);
    }
}
