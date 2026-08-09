<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AudioRecordingMode;
use App\Enum\AudioRecordingStatus;
use App\Repository\AudioRecordingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A batch of audio files a teacher hands to a class (design/design_handoff_enregistrements_audio).
 * Not a file but a set: a name, an audience, and N microphone recordings - common to the whole
 * audience, or individualised student by student, common files remaining possible on top.
 *
 * It replaces the gradebook's audio comments (the former GradeAudioComment, now gone), whose
 * recording and storage chain it inherits unchanged: MediaRecorder at ~24 kbps mono, WebM/Opus
 * (Ogg/Opus on Firefox), posted to the app which writes it to the bucket - see
 * App\Service\AudioUploadService.
 *
 * Neither the status nor the file count is stored: both are read from the files that are there, so
 * a recording completed later has nothing to keep up to date.
 */
#[ORM\Entity(repositoryClass: AudioRecordingRepository::class)]
#[ORM\Table(name: 'audio_recording')]
class AudioRecording
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    /**
     * The options targeted within the class. Empty = the whole class. A student is targeted if they
     * hold AT LEAST one of the selected options (union, like an assignment's option audience - see
     * App\Service\AssignmentAudienceResolver).
     *
     * @var Collection<int, Option>
     */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'audio_recording_option')]
    private Collection $options;

    #[ORM\Column(length: 20, enumType: AudioRecordingMode::class)]
    #[Assert\NotNull]
    private AudioRecordingMode $mode = AudioRecordingMode::Common;

    /**
     * The assignment created from this recording, once the teacher asked for it. It is what moves
     * the status to "Travail créé" and opens the statistics screen: without an assignment, nobody
     * has been asked to listen yet.
     */
    #[ORM\ManyToOne(targetEntity: Assignment::class)]
    #[ORM\JoinColumn(name: 'assignment_id', nullable: true, onDelete: 'SET NULL')]
    private ?Assignment $assignment = null;

    /** @var Collection<int, AudioRecordingFile> */
    #[ORM\OneToMany(mappedBy: 'recording', targetEntity: AudioRecordingFile::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $files;

    public function __construct(Program $program)
    {
        $this->program = $program;
        $this->options = new ArrayCollection();
        $this->files = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        return $this;
    }

    /** @return Collection<int, Option> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(Option $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
        }

        return $this;
    }

    public function removeOption(Option $option): static
    {
        $this->options->removeElement($option);

        return $this;
    }

    public function getMode(): AudioRecordingMode
    {
        return $this->mode;
    }

    public function setMode(AudioRecordingMode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function isIndividual(): bool
    {
        return AudioRecordingMode::Individual === $this->mode;
    }

    public function getAssignment(): ?Assignment
    {
        return $this->assignment;
    }

    public function setAssignment(?Assignment $assignment): static
    {
        $this->assignment = $assignment;

        return $this;
    }

    /** @return Collection<int, AudioRecordingFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(AudioRecordingFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
        }

        return $this;
    }

    public function removeFile(AudioRecordingFile $file): static
    {
        $this->files->removeElement($file);

        return $this;
    }

    /**
     * The files the whole audience hears, in either mode.
     *
     * @return list<AudioRecordingFile>
     */
    public function getCommonFiles(): array
    {
        return array_values(array_filter(
            $this->files->toArray(),
            static fn (AudioRecordingFile $file): bool => !$file->isIndividual(),
        ));
    }

    /**
     * One student's files: the common ones, plus their own. This is exactly what they have to listen
     * to, and therefore what their assignment's completion is judged on.
     *
     * @return list<AudioRecordingFile>
     */
    public function getFilesFor(User $student): array
    {
        return array_values(array_filter(
            $this->files->toArray(),
            static fn (AudioRecordingFile $file): bool => !$file->isIndividual() || $file->getStudent()?->getId() === $student->getId(),
        ));
    }

    /**
     * One student's individual files, without the common ones.
     *
     * @return list<AudioRecordingFile>
     */
    public function getIndividualFilesFor(User $student): array
    {
        return array_values(array_filter(
            $this->files->toArray(),
            static fn (AudioRecordingFile $file): bool => $file->getStudent()?->getId() === $student->getId(),
        ));
    }

    /**
     * The state, read and never stored. A recording is complete once there is something to listen to
     * for everyone: at least one common file in common mode, one file per targeted student in
     * individualised mode - common files do not stand in for those, the whole point of the mode
     * being that each student gets their own.
     *
     * @param list<User> $audience the resolved audience, which the entity cannot work out on its own
     */
    public function getStatus(array $audience): AudioRecordingStatus
    {
        if (null !== $this->assignment) {
            return AudioRecordingStatus::WorkCreated;
        }

        return $this->isComplete($audience) ? AudioRecordingStatus::Complete : AudioRecordingStatus::Draft;
    }

    /** @param list<User> $audience */
    public function isComplete(array $audience): bool
    {
        if (!$this->isIndividual()) {
            return [] !== $this->getCommonFiles();
        }

        if ([] === $audience) {
            return false;
        }

        foreach ($audience as $student) {
            if ([] === $this->getIndividualFilesFor($student)) {
                return false;
            }
        }

        return true;
    }

    /** How many students of the audience have at least one file - step 2's "5 / 8". */
    /** @param list<User> $audience */
    public function countStudentsWithFile(array $audience): int
    {
        $covered = 0;
        foreach ($audience as $student) {
            if ([] !== $this->getIndividualFilesFor($student)) {
                ++$covered;
            }
        }

        return $covered;
    }

    /**
     * The position to give the next file, files being rendered in the order they were recorded.
     */
    public function nextPosition(): int
    {
        $positions = array_map(static fn (AudioRecordingFile $file): int => $file->getPosition(), $this->files->toArray());

        return [] === $positions ? 0 : max($positions) + 1;
    }
}
