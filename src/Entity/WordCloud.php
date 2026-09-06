<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WordCloudProjectionMode;
use App\Enum\WordCloudWordLength;
use App\Repository\WordCloudRepository;
use App\Service\WordCloud\WordCloudWindow;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One « Nuage de mots »: a question asked to a class, a period during which its students may answer
 * it in one or several words, and the settings that decide how those words are counted and shown
 * (design_handoff_nuage_de_mots).
 *
 * **No status column.** `Programmé` / `Ouvert` / `Clos` is read off the window below by
 * App\Service\WordCloud\WordCloudSchedule at every call - see window(). A stored status is a second
 * source of truth, and it is wrong every time a closing hour passes with nobody on the screen.
 *
 * Nothing here counts anything either: a cloud's words, participants and ranking are the sum of its
 * WordCloudSubmission rows, in the same spirit as GameEntry's ledger. What is stored is the
 * question and the rules; what is displayed is always recomputed from the submissions.
 */
#[ORM\Entity(repositoryClass: WordCloudRepository::class)]
#[ORM\Table(name: 'word_cloud')]
// The list screen reads one teacher's clouds for one class, newest first, and the student's banner
// asks the same table for whatever is open right now across their formations.
#[ORM\Index(name: 'idx_word_cloud_program_created', columns: ['program_id', 'created_at'])]
class WordCloud
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private ?string $name = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    private ?string $question = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    // Who runs it. Not the same question as who may pilot it - that is WordCloudVoter's, and it
    // lets the class's other teachers in too.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    #[Assert\NotNull]
    private ?User $teacher = null;

    /**
     * « Options ciblées ». Empty means the whole class - a union, never an intersection: a student
     * holding any one of the named options is asked, exactly as Assignment::$options works.
     *
     * @var Collection<int, Option>
     */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'word_cloud_option')]
    private Collection $options;

    #[ORM\Column(name: 'opens_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $opensAt = null;

    #[ORM\Column(name: 'closes_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closesAt = null;

    // « Ouverture manuelle »: the clock never opens this one, whatever dates it carries.
    #[ORM\Column(name: 'manual_opening')]
    private bool $manualOpening = false;

    #[ORM\Column(name: 'opened_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    // Stamped by « Clore les soumissions », and it outranks a window still running.
    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    // null = « Illimités ».
    #[ORM\Column(name: 'words_per_student', nullable: true)]
    #[Assert\Positive]
    private ?int $wordsPerStudent = 3;

    #[ORM\Column(name: 'word_length', length: 20, enumType: WordCloudWordLength::class)]
    private WordCloudWordLength $wordLength = WordCloudWordLength::OneWord;

    #[ORM\Column(name: 'projection_mode', length: 20, enumType: WordCloudProjectionMode::class)]
    private WordCloudProjectionMode $projectionMode = WordCloudProjectionMode::CloudOnly;

    #[ORM\Column(name: 'group_variants')]
    private bool $groupVariants = true;

    #[ORM\Column(name: 'moderation')]
    private bool $moderation = true;

    #[ORM\Column(name: 'visible_to_students')]
    private bool $visibleToStudents = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, WordCloudSubmission> */
    #[ORM\OneToMany(mappedBy: 'wordCloud', targetEntity: WordCloudSubmission::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $submissions;

    public function __construct()
    {
        $this->options = new ArrayCollection();
        $this->submissions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * The five values WordCloudSchedule needs, and nothing else.
     *
     * This is the only place the entity's dates and the schedule's arithmetic meet: every screen
     * asks the service, never the columns, so « ouvert » means the same thing on the list, on the
     * pilot screen, on the board and at the submission endpoint.
     */
    public function window(): WordCloudWindow
    {
        return new WordCloudWindow($this->opensAt, $this->closesAt, $this->manualOpening, $this->openedAt, $this->closedAt);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }

    public function setQuestion(string $question): static
    {
        $this->question = $question;

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

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    public function setTeacher(?User $teacher): static
    {
        $this->teacher = $teacher;

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

    public function getOpensAt(): ?\DateTimeImmutable
    {
        return $this->opensAt;
    }

    public function setOpensAt(?\DateTimeImmutable $opensAt): static
    {
        $this->opensAt = $opensAt;

        return $this;
    }

    public function getClosesAt(): ?\DateTimeImmutable
    {
        return $this->closesAt;
    }

    public function setClosesAt(?\DateTimeImmutable $closesAt): static
    {
        $this->closesAt = $closesAt;

        return $this;
    }

    public function isManualOpening(): bool
    {
        return $this->manualOpening;
    }

    public function setManualOpening(bool $manualOpening): static
    {
        $this->manualOpening = $manualOpening;

        return $this;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function setOpenedAt(?\DateTimeImmutable $openedAt): static
    {
        $this->openedAt = $openedAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function getWordsPerStudent(): ?int
    {
        return $this->wordsPerStudent;
    }

    public function setWordsPerStudent(?int $wordsPerStudent): static
    {
        $this->wordsPerStudent = $wordsPerStudent;

        return $this;
    }

    public function getWordLength(): WordCloudWordLength
    {
        return $this->wordLength;
    }

    public function setWordLength(WordCloudWordLength $wordLength): static
    {
        $this->wordLength = $wordLength;

        return $this;
    }

    public function getProjectionMode(): WordCloudProjectionMode
    {
        return $this->projectionMode;
    }

    public function setProjectionMode(WordCloudProjectionMode $projectionMode): static
    {
        $this->projectionMode = $projectionMode;

        return $this;
    }

    public function isGroupVariants(): bool
    {
        return $this->groupVariants;
    }

    public function setGroupVariants(bool $groupVariants): static
    {
        $this->groupVariants = $groupVariants;

        return $this;
    }

    public function isModeration(): bool
    {
        return $this->moderation;
    }

    public function setModeration(bool $moderation): static
    {
        $this->moderation = $moderation;

        return $this;
    }

    public function isVisibleToStudents(): bool
    {
        return $this->visibleToStudents;
    }

    public function setVisibleToStudents(bool $visibleToStudents): static
    {
        $this->visibleToStudents = $visibleToStudents;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, WordCloudSubmission> */
    public function getSubmissions(): Collection
    {
        return $this->submissions;
    }
}
