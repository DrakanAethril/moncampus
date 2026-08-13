<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuizTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A teacher's personal, reusable quiz template - see design/design_campus_manager/README.md,
 * "Générateur de quiz" section, and reference/Générateur de quiz.dc.html screens 1a/1b/1n. Owned
 * by a teacher, not a Program, exactly like SequenceTemplate - visible only in Gestion > Mes quiz.
 * Launching it into a class (QuizInstance, App\Service\QuizInstantiationService) makes a frozen,
 * one-way copy of every question/answer plus the launch settings: editing this template afterward
 * never touches any instance already created from it. Hard-deleted like SequenceTemplate - a
 * teacher's own draft content, no audit trail needed beyond $teacher/AuditableTrait.
 */
#[ORM\Entity(repositoryClass: QuizTemplateRepository::class)]
#[ORM\Table(name: 'quiz_template')]
class QuizTemplate
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    private ?User $teacher = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    // Free text, deliberately not linked to the real Option entity nor to a
    // LibraryOptionTag - same "teacher-private tag" reasoning as SequenceTemplate's niveau/option,
    // but simple enough here (a single line, e.g. "SISR" or "SISR · SLAM") not to need a whole tag
    // system of its own.
    #[ORM\Column(name: 'subject', length: 255, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // Launch defaults (screen 1n) - pre-fill the "Lancer" form (1c) but are frozen onto each
    // QuizInstance at launch time, same as every other field here; changing these later never
    // touches an already-launched instance.
    #[ORM\Column(name: 'default_question_count')]
    #[Assert\Positive]
    private int $defaultQuestionCount = 20;

    // Null means no time limit at all. A question can follow this default, lift it for itself, or
    // set its own count - see QuizQuestion::$timeMode.
    #[ORM\Column(name: 'default_seconds_per_question', nullable: true)]
    #[Assert\Positive]
    private ?int $defaultSecondsPerQuestion = 30;

    #[ORM\Column(name: 'default_same_questions_for_all', options: ['default' => true])]
    private bool $defaultSameQuestionsForAll = true;

    #[ORM\Column(name: 'default_question_order_per_student', options: ['default' => true])]
    private bool $defaultQuestionOrderPerStudent = true;

    #[ORM\Column(name: 'default_answer_order_per_student', options: ['default' => false])]
    private bool $defaultAnswerOrderPerStudent = false;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    /** @var Collection<int, QuizQuestion> */
    #[ORM\OneToMany(mappedBy: 'quizTemplate', targetEntity: QuizQuestion::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $questions;

    /**
     * Where this quiz is *used* in the library - added 2026-08-13 with $sequenceTemplates.
     *
     * Two relation tables and not two nullable foreign keys, because what the « Quiz de la séquence »
     * card shows ("2 séances sur 4") measures **usage** and not provenance, and the two do not have the
     * same cardinality. Provenance is unique by nature: this quiz was produced from that séance, once.
     * Usage is multiple on both sides: a réactivation quiz serves in S2 *and* in S3, and a séance
     * happily carries a diagnostic at its opening and a final at its end. With one FK per level, making
     * a quiz serve two séances means duplicating it - precisely what a library exists to avoid.
     *
     * Two tables rather than one with a nullable seance_template_id: the Ansible kit's final QCM is
     * about the whole séquence and about no séance in particular, and a half-absent key is a table that
     * means two things.
     *
     * `ON DELETE CASCADE` on all four columns, declared rather than inherited. Deleting a séance
     * *detaches* the quiz; the quiz stays in the teacher's library, which is its home. It is never the
     * séance's property, which is also why the collections live on this side.
     *
     * @var Collection<int, SeanceTemplate>
     */
    #[ORM\ManyToMany(targetEntity: SeanceTemplate::class, inversedBy: 'quizTemplates')]
    #[ORM\JoinTable(name: 'quiz_template_seance_template')]
    #[ORM\JoinColumn(name: 'quiz_template_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'seance_template_id', onDelete: 'CASCADE')]
    private Collection $seanceTemplates;

    /** @var Collection<int, SequenceTemplate> */
    #[ORM\ManyToMany(targetEntity: SequenceTemplate::class, inversedBy: 'quizTemplates')]
    #[ORM\JoinTable(name: 'quiz_template_sequence_template')]
    #[ORM\JoinColumn(name: 'quiz_template_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'sequence_template_id', onDelete: 'CASCADE')]
    private Collection $sequenceTemplates;

    public function __construct(User $teacher)
    {
        $this->teacher = $teacher;
        $this->questions = new ArrayCollection();
        $this->seanceTemplates = new ArrayCollection();
        $this->sequenceTemplates = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDefaultQuestionCount(): int
    {
        return $this->defaultQuestionCount;
    }

    public function setDefaultQuestionCount(int $defaultQuestionCount): static
    {
        $this->defaultQuestionCount = $defaultQuestionCount;

        return $this;
    }

    public function getDefaultSecondsPerQuestion(): ?int
    {
        return $this->defaultSecondsPerQuestion;
    }

    public function setDefaultSecondsPerQuestion(?int $defaultSecondsPerQuestion): static
    {
        $this->defaultSecondsPerQuestion = $defaultSecondsPerQuestion;

        return $this;
    }

    public function isDefaultSameQuestionsForAll(): bool
    {
        return $this->defaultSameQuestionsForAll;
    }

    public function setDefaultSameQuestionsForAll(bool $defaultSameQuestionsForAll): static
    {
        $this->defaultSameQuestionsForAll = $defaultSameQuestionsForAll;

        return $this;
    }

    public function isDefaultQuestionOrderPerStudent(): bool
    {
        return $this->defaultQuestionOrderPerStudent;
    }

    public function setDefaultQuestionOrderPerStudent(bool $defaultQuestionOrderPerStudent): static
    {
        $this->defaultQuestionOrderPerStudent = $defaultQuestionOrderPerStudent;

        return $this;
    }

    public function isDefaultAnswerOrderPerStudent(): bool
    {
        return $this->defaultAnswerOrderPerStudent;
    }

    public function setDefaultAnswerOrderPerStudent(bool $defaultAnswerOrderPerStudent): static
    {
        $this->defaultAnswerOrderPerStudent = $defaultAnswerOrderPerStudent;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    /** @return Collection<int, QuizQuestion> */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(QuizQuestion $question): static
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
        }

        return $this;
    }

    public function removeQuestion(QuizQuestion $question): static
    {
        $this->questions->removeElement($question);

        return $this;
    }

    /** @return Collection<int, SeanceTemplate> */
    public function getSeanceTemplates(): Collection
    {
        return $this->seanceTemplates;
    }

    /**
     * Both sides are kept in step here, on the owning side, because the inverse collection is what the
     * « Quiz de la séquence » card reads: a link added through this method and not mirrored would be in
     * the database and absent from the card until the next request.
     */
    public function addSeanceTemplate(SeanceTemplate $seance): static
    {
        if (!$this->seanceTemplates->contains($seance)) {
            $this->seanceTemplates->add($seance);
            $seance->getQuizTemplates()->add($this);
        }

        return $this;
    }

    public function removeSeanceTemplate(SeanceTemplate $seance): static
    {
        if ($this->seanceTemplates->removeElement($seance)) {
            $seance->getQuizTemplates()->removeElement($this);
        }

        return $this;
    }

    /** @return Collection<int, SequenceTemplate> */
    public function getSequenceTemplates(): Collection
    {
        return $this->sequenceTemplates;
    }

    public function addSequenceTemplate(SequenceTemplate $sequence): static
    {
        if (!$this->sequenceTemplates->contains($sequence)) {
            $this->sequenceTemplates->add($sequence);
            $sequence->getQuizTemplates()->add($this);
        }

        return $this;
    }

    public function removeSequenceTemplate(SequenceTemplate $sequence): static
    {
        if ($this->sequenceTemplates->removeElement($sequence)) {
            $sequence->getQuizTemplates()->removeElement($this);
        }

        return $this;
    }
}
