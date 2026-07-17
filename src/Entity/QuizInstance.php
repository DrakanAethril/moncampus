<?php

namespace App\Entity;

use App\Enum\QuizMode;
use App\Enum\QuizScoring;
use App\Repository\QuizInstanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen, one-way copy of a QuizTemplate, launched against a specific Program (class) - see
 * design/design_campus_manager/README.md, "Générateur de quiz" section, screen 1c. Built by
 * App\Service\QuizInstantiationService: every question/answer is deep-copied into
 * QuizInstanceQuestion/QuizInstanceAnswer, and every launch setting below is frozen at that
 * moment. Editing the source QuizTemplate afterward never touches this row, exactly like
 * SequenceInstance/SequenceTemplate.
 */
#[ORM\Entity(repositoryClass: QuizInstanceRepository::class)]
#[ORM\Table(name: 'quiz_instance')]
class QuizInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private ?Program $program = null;

    // Provenance only, not a live link - see SequenceInstance::$sourceTemplate's docblock for the
    // same SET NULL reasoning (deleting the template later must never break an already-launched
    // instance).
    #[ORM\ManyToOne(targetEntity: QuizTemplate::class)]
    #[ORM\JoinColumn(name: 'source_template_id', nullable: true, onDelete: 'SET NULL')]
    private ?QuizTemplate $sourceTemplate = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    // ---- Copied from the template at launch time, frozen from that point on ----
    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subject = null;

    // ---- Mode & window ----
    #[ORM\Column(length: 20, enumType: QuizMode::class)]
    private QuizMode $mode = QuizMode::Entrainement;

    #[ORM\Column(name: 'opens_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $opensAt = null;

    #[ORM\Column(name: 'closes_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closesAt = null;

    // ---- Draw & fairness (les 3 toggles d'équité) ----
    #[ORM\Column(name: 'question_count')]
    private int $questionCount = 20;

    // Facile/Moyen/Difficile percentages of the draw (sum to 100) and their resolved counts (sum
    // to $questionCount) - both frozen here rather than recomputed later, so a future rounding-
    // algorithm change never silently reshuffles an already-launched instance's recipe. See
    // App\Service\QuizDifficultyDistributionResolver for how the slider position becomes these.
    #[ORM\Column(name: 'difficulty_facile_percent')]
    private int $difficultyFacilePercent = 20;

    #[ORM\Column(name: 'difficulty_moyen_percent')]
    private int $difficultyMoyenPercent = 60;

    #[ORM\Column(name: 'difficulty_difficile_percent')]
    private int $difficultyDifficilePercent = 20;

    #[ORM\Column(name: 'difficulty_facile_count')]
    private int $difficultyFacileCount = 0;

    #[ORM\Column(name: 'difficulty_moyen_count')]
    private int $difficultyMoyenCount = 0;

    #[ORM\Column(name: 'difficulty_difficile_count')]
    private int $difficultyDifficileCount = 0;

    #[ORM\Column(name: 'same_questions_for_all', options: ['default' => true])]
    private bool $sameQuestionsForAll = true;

    #[ORM\Column(name: 'question_order_per_student', options: ['default' => true])]
    private bool $questionOrderPerStudent = true;

    #[ORM\Column(name: 'answer_order_per_student', options: ['default' => false])]
    private bool $answerOrderPerStudent = false;

    // ---- Time ----
    #[ORM\Column(name: 'seconds_per_question', nullable: true)]
    private ?int $secondsPerQuestion = 30;

    #[ORM\Column(name: 'global_time_minutes', nullable: true)]
    private ?int $globalTimeMinutes = null;

    // ---- Scoring (deliberately unrelated to any carnet de notes) ----
    #[ORM\Column(length: 20, enumType: QuizScoring::class)]
    private QuizScoring $scoring = QuizScoring::Note20;

    #[ORM\Column(name: 'score_visible_immediately', options: ['default' => true])]
    private bool $scoreVisibleImmediately = true;

    /** @var Collection<int, QuizInstanceQuestion> */
    #[ORM\OneToMany(mappedBy: 'quizInstance', targetEntity: QuizInstanceQuestion::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $questions;

    public function __construct(Program $program, User $createdBy)
    {
        $this->program = $program;
        $this->createdBy = $createdBy;
        $this->creationDate = new \DateTimeImmutable();
        $this->questions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getSourceTemplate(): ?QuizTemplate
    {
        return $this->sourceTemplate;
    }

    public function setSourceTemplate(?QuizTemplate $sourceTemplate): static
    {
        $this->sourceTemplate = $sourceTemplate;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
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

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getMode(): QuizMode
    {
        return $this->mode;
    }

    public function setMode(QuizMode $mode): static
    {
        $this->mode = $mode;

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

    public function getQuestionCount(): int
    {
        return $this->questionCount;
    }

    public function setQuestionCount(int $questionCount): static
    {
        $this->questionCount = $questionCount;

        return $this;
    }

    public function getDifficultyFacilePercent(): int
    {
        return $this->difficultyFacilePercent;
    }

    public function getDifficultyMoyenPercent(): int
    {
        return $this->difficultyMoyenPercent;
    }

    public function getDifficultyDifficilePercent(): int
    {
        return $this->difficultyDifficilePercent;
    }

    public function setDifficultyPercents(int $facile, int $moyen, int $difficile): static
    {
        $this->difficultyFacilePercent = $facile;
        $this->difficultyMoyenPercent = $moyen;
        $this->difficultyDifficilePercent = $difficile;

        return $this;
    }

    public function getDifficultyFacileCount(): int
    {
        return $this->difficultyFacileCount;
    }

    public function getDifficultyMoyenCount(): int
    {
        return $this->difficultyMoyenCount;
    }

    public function getDifficultyDifficileCount(): int
    {
        return $this->difficultyDifficileCount;
    }

    public function setDifficultyCounts(int $facile, int $moyen, int $difficile): static
    {
        $this->difficultyFacileCount = $facile;
        $this->difficultyMoyenCount = $moyen;
        $this->difficultyDifficileCount = $difficile;

        return $this;
    }

    public function isSameQuestionsForAll(): bool
    {
        return $this->sameQuestionsForAll;
    }

    public function setSameQuestionsForAll(bool $sameQuestionsForAll): static
    {
        $this->sameQuestionsForAll = $sameQuestionsForAll;

        return $this;
    }

    public function isQuestionOrderPerStudent(): bool
    {
        return $this->questionOrderPerStudent;
    }

    public function setQuestionOrderPerStudent(bool $questionOrderPerStudent): static
    {
        $this->questionOrderPerStudent = $questionOrderPerStudent;

        return $this;
    }

    public function isAnswerOrderPerStudent(): bool
    {
        return $this->answerOrderPerStudent;
    }

    public function setAnswerOrderPerStudent(bool $answerOrderPerStudent): static
    {
        $this->answerOrderPerStudent = $answerOrderPerStudent;

        return $this;
    }

    public function getSecondsPerQuestion(): ?int
    {
        return $this->secondsPerQuestion;
    }

    public function setSecondsPerQuestion(?int $secondsPerQuestion): static
    {
        $this->secondsPerQuestion = $secondsPerQuestion;

        return $this;
    }

    public function getGlobalTimeMinutes(): ?int
    {
        return $this->globalTimeMinutes;
    }

    public function setGlobalTimeMinutes(?int $globalTimeMinutes): static
    {
        $this->globalTimeMinutes = $globalTimeMinutes;

        return $this;
    }

    public function getScoring(): QuizScoring
    {
        return $this->scoring;
    }

    public function setScoring(QuizScoring $scoring): static
    {
        $this->scoring = $scoring;

        return $this;
    }

    public function isScoreVisibleImmediately(): bool
    {
        return $this->scoreVisibleImmediately;
    }

    public function setScoreVisibleImmediately(bool $scoreVisibleImmediately): static
    {
        $this->scoreVisibleImmediately = $scoreVisibleImmediately;

        return $this;
    }

    /** @return Collection<int, QuizInstanceQuestion> */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(QuizInstanceQuestion $question): static
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
        }

        return $this;
    }
}
