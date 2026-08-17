<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuestionDifficulty;
use App\Enum\QuestionTimeMode;
use App\Enum\QuestionType;
use App\Repository\QuizQuestionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One question of a QuizTemplate's bank - screen 1b. $difficulty is nullable: an unset difficulty
 * counts as QuestionDifficulty::Moyen everywhere it's read (draw distribution, dot indicator), it
 * is not itself a "no difficulty" state that needs separate handling.
 *
 * For QuestionType::TexteATrous, $label holds the statement with "..." blank markers and the
 * answers live in the trait's JSON column instead of $answers - see App\Entity\QuizQuestionDefinitionTrait.
 */
#[ORM\Entity(repositoryClass: QuizQuestionRepository::class)]
#[ORM\Table(name: 'quiz_question')]
class QuizQuestion implements QuizQuestionDefinition
{
    use QuizQuestionDefinitionTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The library file this row was linked from, when it was one
     * (design/validated/file-library.md, "The link, on nine existing tables").
     *
     * **The row keeps its own storage key**, copied from the node. That is the decision that makes
     * the whole feature cheap: every reader - a Twig template, file_url(), the mobile API, the PDF
     * exports, the mail attachment builder - is untouched, because nothing about *reading* a file
     * changes. This foreign key exists for one purpose: to answer "where is this file used", with a
     * real index and a real constraint rather than a polymorphic (target_type, target_id) table
     * Doctrine could not key - and which nothing would clean up when a host disappears.
     *
     * `SET NULL` and never `CASCADE`: removing the links is App\Service\FileLibraryLinks's job, done
     * deliberately when the teacher confirms « Supprimer partout ». A cascade here would delete the
     * *host* row - the quiz question, the séquence resource, the video and its statistics - as a
     * database side effect nobody can see.
     */
    #[ORM\ManyToOne(targetEntity: FileLibraryNode::class)]
    #[ORM\JoinColumn(name: 'library_node_id', nullable: true, onDelete: 'SET NULL')]
    private ?FileLibraryNode $libraryNode = null;

    #[ORM\ManyToOne(targetEntity: QuizTemplate::class, inversedBy: 'questions')]
    #[ORM\JoinColumn(name: 'quiz_template_id', nullable: false)]
    private ?QuizTemplate $quizTemplate = null;

    #[ORM\Column(length: 20, enumType: QuestionType::class)]
    private QuestionType $type = QuestionType::Qcm;

    #[ORM\Column(length: 20, enumType: QuestionDifficulty::class, nullable: true)]
    private ?QuestionDifficulty $difficulty = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $label = null;

    // S3 storage key (App\Service\FileUploadService), same convention as
    // MessageAttachment::$storageKey/LessonLogAttachment::$storageKey - null means no image.
    #[ORM\Column(name: 'image_storage_key', length: 255, nullable: true)]
    private ?string $imageStorageKey = null;

    /**
     * The media an import named but could not attach - a file name, or the address it was given as.
     * Deliberately distinct from $imageStorageKey: this one is a name to honour, the other a stored
     * object. Attaching the file clears the first and fills the second (attachMedia()).
     *
     * An AI cannot deposit a file in the application, only name the one it was shown; a question
     * that names one is created all the same and marked incomplete
     * (App\Service\QuizQuestionCompleteness).
     */
    #[ORM\Column(name: 'expected_media_name', length: 255, nullable: true)]
    private ?string $expectedMediaName = null;

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    // Per-question time, on top of the quiz's own default - see App\Enum\QuestionTimeMode.
    #[ORM\Column(name: 'time_mode', length: 20, enumType: QuestionTimeMode::class, options: ['default' => 'quiz'])]
    private QuestionTimeMode $timeMode = QuestionTimeMode::Quiz;

    #[ORM\Column(name: 'time_seconds', nullable: true)]
    #[Assert\Positive]
    private ?int $timeSeconds = null;

    /** @var Collection<int, QuizAnswer> */
    #[ORM\OneToMany(mappedBy: 'question', targetEntity: QuizAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $answers;

    public function __construct(QuizTemplate $quizTemplate)
    {
        $this->quizTemplate = $quizTemplate;
        $this->answers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuizTemplate(): ?QuizTemplate
    {
        return $this->quizTemplate;
    }

    public function getType(): QuestionType
    {
        return $this->type;
    }

    public function setType(QuestionType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDifficulty(): ?QuestionDifficulty
    {
        return $this->difficulty;
    }

    // Never returns null - every read site treats an unset difficulty as Moyen (see class docblock).
    public function getEffectiveDifficulty(): QuestionDifficulty
    {
        return $this->difficulty ?? QuestionDifficulty::Moyen;
    }

    public function setDifficulty(?QuestionDifficulty $difficulty): static
    {
        $this->difficulty = $difficulty;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getImageStorageKey(): ?string
    {
        return $this->imageStorageKey;
    }

    public function setImageStorageKey(?string $imageStorageKey): static
    {
        $this->imageStorageKey = $imageStorageKey;

        return $this;
    }

    public function getExpectedMediaName(): ?string
    {
        return $this->expectedMediaName;
    }

    public function setExpectedMediaName(?string $expectedMediaName): static
    {
        $this->expectedMediaName = $expectedMediaName;

        return $this;
    }

    /**
     * Attaching the file at last: the stored object replaces the name that was waiting for it. The
     * two never coexist, which is what makes "incomplete" a single readable state.
     */
    public function attachMedia(string $storageKey): static
    {
        $this->imageStorageKey = $storageKey;
        $this->expectedMediaName = null;

        return $this;
    }

    public function getOrderIndex(): int
    {
        return $this->orderIndex;
    }

    public function setOrderIndex(int $orderIndex): static
    {
        $this->orderIndex = $orderIndex;

        return $this;
    }

    /** @return Collection<int, QuizAnswer> */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(QuizAnswer $answer): static
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
        }

        return $this;
    }

    public function removeAnswer(QuizAnswer $answer): static
    {
        $this->answers->removeElement($answer);

        return $this;
    }

    public function getTimeMode(): QuestionTimeMode
    {
        return $this->timeMode;
    }

    public function setTimeMode(QuestionTimeMode $timeMode): static
    {
        $this->timeMode = $timeMode;

        return $this;
    }

    public function getTimeSeconds(): ?int
    {
        return $this->timeSeconds;
    }

    public function setTimeSeconds(?int $timeSeconds): static
    {
        $this->timeSeconds = $timeSeconds;

        return $this;
    }

    /** The seconds this question actually gets, null meaning no limit. */
    public function resolveSeconds(?int $quizSeconds): ?int
    {
        return $this->timeMode->resolveSeconds($this->timeSeconds, $quizSeconds);
    }

    public function getLibraryNode(): ?FileLibraryNode
    {
        return $this->libraryNode;
    }

    public function setLibraryNode(?FileLibraryNode $libraryNode): self
    {
        $this->libraryNode = $libraryNode;

        return $this;
    }
}
