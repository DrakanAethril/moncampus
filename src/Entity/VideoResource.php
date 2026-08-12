<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VideoResourceStatus;
use App\Repository\VideoResourceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A set of video files a teacher hands to a class, and whose watching is measured.
 *
 * Modelled on App\Entity\AudioRecording, with one deliberate difference: there is no individualised
 * mode. Per-student audio exists because teachers record spoken feedback for one student at a time;
 * a course video is the opposite - it is made once and watched by everyone. Adding the mode "for
 * symmetry" would carry a nullable student through every file, every query and every screen for a
 * use case nobody has.
 */
#[ORM\Entity(repositoryClass: VideoResourceRepository::class)]
#[ORM\Table(name: 'video_resource')]
class VideoResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private ?Program $program = null;

    /**
     * Narrows the target inside the program, exactly as an audio recording does: empty means the
     * whole class.
     *
     * @var Collection<int, Option>
     */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'video_resource_option')]
    private Collection $options;

    // The work built from this video, once the teacher asks for one. Null while the video is only
    // course material - watching it is then not something the student owes anybody.
    #[ORM\ManyToOne(targetEntity: Assignment::class)]
    #[ORM\JoinColumn(name: 'assignment_id', nullable: true, onDelete: 'SET NULL')]
    private ?Assignment $assignment = null;

    /** @var Collection<int, VideoResourceFile> */
    #[ORM\OneToMany(mappedBy: 'resource', targetEntity: VideoResourceFile::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $files;

    /**
     * The bank the questions imported *into this video* are written to (créas 5B, screen 3 bis).
     *
     * A video's markers point at ordinary QuizQuestion rows, which have to belong to some template;
     * rather than scatter them, an import from a video creates one bank named after the video and
     * appends to it every time. It is a real library quiz, so a teacher can open it, correct a
     * typo, or launch it as a quiz of its own - and picking a marker's question from any other bank
     * stays possible, which is what makes the video borrow the library rather than own a corner of it.
     */
    #[ORM\ManyToOne(targetEntity: QuizTemplate::class)]
    #[ORM\JoinColumn(name: 'question_template_id', nullable: true, onDelete: 'SET NULL')]
    private ?QuizTemplate $questionTemplate = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private ?User $createdBy = null;

    public function __construct(Program $program, User $createdBy)
    {
        $this->program = $program;
        $this->createdBy = $createdBy;
        $this->creationDate = new \DateTimeImmutable();
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

    public function getAssignment(): ?Assignment
    {
        return $this->assignment;
    }

    public function setAssignment(?Assignment $assignment): static
    {
        $this->assignment = $assignment;

        return $this;
    }

    public function getQuestionTemplate(): ?QuizTemplate
    {
        return $this->questionTemplate;
    }

    public function setQuestionTemplate(?QuizTemplate $questionTemplate): static
    {
        $this->questionTemplate = $questionTemplate;

        return $this;
    }

    /** @return Collection<int, VideoResourceFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(VideoResourceFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setResource($this);
        }

        return $this;
    }

    public function removeFile(VideoResourceFile $file): static
    {
        $this->files->removeElement($file);

        return $this;
    }

    /**
     * What the list's "Statut" column reads. A video carrying no file at all is still a draft -
     * there is nothing to watch - and one an assignment was built from says so, which is also what
     * keeps a second assignment from being given on the same video.
     */
    public function getStatus(): VideoResourceStatus
    {
        if (null !== $this->assignment) {
            return VideoResourceStatus::WorkCreated;
        }

        return $this->files->isEmpty() ? VideoResourceStatus::Draft : VideoResourceStatus::Complete;
    }

    /** Total running time of the set, which is what "il reste X à visionner" is computed against. */
    public function getTotalDurationSeconds(): int
    {
        $total = 0;
        foreach ($this->files as $file) {
            $total += $file->getDurationSeconds();
        }

        return $total;
    }

    public function nextPosition(): int
    {
        $highest = 0;
        foreach ($this->files as $file) {
            $highest = max($highest, $file->getPosition());
        }

        return $highest + 1;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }
}
