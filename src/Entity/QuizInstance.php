<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuizMode;
use App\Enum\QuizScoring;
use App\Enum\QuizSupervisionPolicy;
use App\Repository\QuizInstanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen, one-way copy of one *or several* QuizTemplates, launched against a specific Program
 * (class) - see design/design_campus_manager/README.md, "Générateur de quiz" section, screen 1c.
 * Built by App\Service\QuizInstantiationService: every question/answer is deep-copied into
 * QuizInstanceQuestion/QuizInstanceAnswer, and every launch setting below is frozen at that
 * moment. Editing the source QuizTemplate afterward never touches this row, exactly like
 * SequenceInstance/SequenceTemplate.
 *
 * Several templates can be merged into one pool at launch time ("un gros quiz de fin de séquence"
 * made of the five séance quizzes): their questions land in the same $questions collection, and
 * the draw (App\Service\QuizDrawService) sees a single undifferentiated bank - it picks by
 * difficulty across the whole merge, never per source template. $name then stops being the
 * template's own name and becomes whatever the teacher labelled that launch.
 */
#[ORM\Entity(repositoryClass: QuizInstanceRepository::class)]
#[ORM\Table(name: 'quiz_instance')]
class QuizInstance implements AccessConditionHost
{
    use AccessConditionTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private ?Program $program = null;

    // Provenance only, not a live link - see SequenceInstance::$sourceTemplate's docblock for the
    // same SET NULL reasoning (deleting the template later must never break an already-launched
    // instance). When several templates were merged this holds the first of them, the one the
    // launch started from; $sourceTemplates below holds the whole pool.
    #[ORM\ManyToOne(targetEntity: QuizTemplate::class)]
    #[ORM\JoinColumn(name: 'source_template_id', nullable: true, onDelete: 'SET NULL')]
    private ?QuizTemplate $sourceTemplate = null;

    /**
     * Every template merged into this instance's pool, $sourceTemplate included. Provenance again,
     * so the join rows are dropped with the template (CASCADE) rather than kept dangling - the
     * questions themselves are already copied and survive on their own, exactly like the SET NULL
     * above. Ordered by name because a ManyToMany has no order of its own and this is only ever
     * read to answer "de quoi ce quiz est-il fait ?".
     *
     * @var Collection<int, QuizTemplate>
     */
    #[ORM\ManyToMany(targetEntity: QuizTemplate::class)]
    #[ORM\JoinTable(name: 'quiz_instance_source_template')]
    #[ORM\JoinColumn(name: 'quiz_instance_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'quiz_template_id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $sourceTemplates;

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

    // ---- Draw & fairness (the 3 fairness toggles) ----
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

    // ---- Mode contrôle (évaluation only) ----
    /**
     * Whether this évaluation is supervised: the page-event journal is kept, the student is told so
     * at the door, and the teacher gets the timeline. Never true on an entraînement - an
     * entraînement is meant to be redone, searched and discussed, and supervising it would only
     * wear the setting out. The launch form forces it back to false server-side when the mode goes
     * back to Entraînement, and isSupervised() asks the mode again below, so a row edited by hand
     * is harmless too.
     */
    #[ORM\Column(name: 'supervised')]
    private bool $supervised = false;

    #[ORM\Column(name: 'supervision_policy', length: 16, enumType: QuizSupervisionPolicy::class)]
    private QuizSupervisionPolicy $supervisionPolicy = QuizSupervisionPolicy::Warn;

    /**
     * How long an absence must last to count as one. Below it, it is a notification, a screen going
     * to sleep, a spell-checker - not a search. Settable per quiz because the right value is a
     * measurement nobody has yet (see the design's "Reste ouvert"); the twenty seconds of display
     * the rule also demands are not, being the physical floor of looking something up.
     */
    #[ORM\Column(name: 'supervision_exit_secs', type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $supervisionExitSeconds = 8;

    /** After how many exits the copy is handed in - only read under QuizSupervisionPolicy::Autosubmit. */
    #[ORM\Column(name: 'supervision_submit_at', type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $supervisionSubmitAt = null;

    // ---- Deactivation ----
    // Not a deletion: a deactivated instance disappears from every student surface (list, passation,
    // mobile API) while its attempts, scores and statistics stay exactly where the teacher left
    // them - which is the whole point of not offering a "supprimer" here. Nullable timestamp rather
    // than a boolean because "when was this closed down" is the question one actually asks of it,
    // and null already spells "active".
    #[ORM\Column(name: 'deactivated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'deactivated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $deactivatedBy = null;

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
        $this->sourceTemplates = new ArrayCollection();
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

    /** @return Collection<int, QuizTemplate> */
    public function getSourceTemplates(): Collection
    {
        return $this->sourceTemplates;
    }

    public function addSourceTemplate(QuizTemplate $sourceTemplate): static
    {
        if (!$this->sourceTemplates->contains($sourceTemplate)) {
            $this->sourceTemplates->add($sourceTemplate);
        }

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

    // Entraînement is "toujours ouvert" (screen 1d) regardless of $opensAt/$closesAt - those only
    // gate Évaluation's fenêtre. Compute-live, same convention as SignupList::isRegistrationOpen().
    //
    // Deactivation is checked first and applies to both modes: it is the one thing that closes an
    // entraînement. Putting it here rather than only in the callers is what makes
    // QuizAttemptStarter refuse a deactivated quiz - and, through it, both the web passation and
    // the mobile API - without either of them having to remember the rule.
    public function isOpenNow(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if (QuizMode::Entrainement === $this->mode) {
            return true;
        }

        $now = new \DateTimeImmutable();

        if (null !== $this->opensAt && $now < $this->opensAt) {
            return false;
        }

        return null === $this->closesAt || $now <= $this->closesAt;
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

    /**
     * The mode is asked again on purpose: « le mode contrôle n'existe qu'en Évaluation » is the
     * rule, and reading it here means no screen and no service has to remember it.
     */
    public function isSupervised(): bool
    {
        return $this->supervised && QuizMode::Evaluation === $this->mode;
    }

    public function setSupervised(bool $supervised): static
    {
        $this->supervised = $supervised;

        return $this;
    }

    public function getSupervisionPolicy(): QuizSupervisionPolicy
    {
        return $this->supervisionPolicy;
    }

    public function setSupervisionPolicy(QuizSupervisionPolicy $supervisionPolicy): static
    {
        $this->supervisionPolicy = $supervisionPolicy;

        return $this;
    }

    public function getSupervisionExitSeconds(): int
    {
        return $this->supervisionExitSeconds;
    }

    public function setSupervisionExitSeconds(int $supervisionExitSeconds): static
    {
        $this->supervisionExitSeconds = max(1, $supervisionExitSeconds);

        return $this;
    }

    public function getSupervisionSubmitAt(): ?int
    {
        return $this->supervisionSubmitAt;
    }

    /** Never fewer than three exits: the design forbids handing a copy in on one stray click. */
    public function setSupervisionSubmitAt(?int $supervisionSubmitAt): static
    {
        $this->supervisionSubmitAt = null === $supervisionSubmitAt ? null : max(3, $supervisionSubmitAt);

        return $this;
    }

    public function isActive(): bool
    {
        return null === $this->deactivatedAt;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function getDeactivatedBy(): ?User
    {
        return $this->deactivatedBy;
    }

    // Idempotent on purpose: re-posting the form must not rewrite the date the quiz was actually
    // closed down, which is what the teacher's screen reports.
    public function deactivate(User $by): static
    {
        if ($this->isActive()) {
            $this->deactivatedAt = new \DateTimeImmutable();
            $this->deactivatedBy = $by;
        }

        return $this;
    }

    public function reactivate(): static
    {
        $this->deactivatedAt = null;
        $this->deactivatedBy = null;

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

    public function getAccessConditionProgram(): ?Program
    {
        return $this->program;
    }

    public function getAccessConditionLabel(): string
    {
        return $this->name ?? '';
    }
}
