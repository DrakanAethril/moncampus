<?php

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

    #[ORM\Column(name: 'default_seconds_per_question')]
    #[Assert\Positive]
    private int $defaultSecondsPerQuestion = 30;

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

    public function __construct(User $teacher)
    {
        $this->teacher = $teacher;
        $this->questions = new ArrayCollection();
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

    public function getDefaultSecondsPerQuestion(): int
    {
        return $this->defaultSecondsPerQuestion;
    }

    public function setDefaultSecondsPerQuestion(int $defaultSecondsPerQuestion): static
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
}
