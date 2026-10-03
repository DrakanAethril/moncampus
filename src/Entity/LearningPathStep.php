<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LearningPathStepType;
use App\Repository\LearningPathStepRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One step of a learning path: a course, or a validation quiz
 * (design/validated/cours-en-ligne.md, §10).
 *
 * A quiz step points at a quiz of the **library** (App\Entity\QuizTemplate), never at a launched
 * one - a launched quiz belongs to a class, and a path has none. It carries the threshold that
 * validates it and how many questions an attempt draws; a null count draws them all.
 *
 * A step whose course has been taken offline, or whose quiz has been deleted from the library, is
 * *unavailable*: it is skipped, it blocks nothing, and it says so
 * (App\Service\LearningPath\LearningPathRule).
 */
#[ORM\Entity(repositoryClass: LearningPathStepRepository::class)]
#[ORM\Table(name: 'learning_path_step')]
class LearningPathStep
{
    public const int DEFAULT_PASS_PERCENT = 70;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LearningPath::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LearningPath $path = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 10, enumType: LearningPathStepType::class)]
    private LearningPathStepType $type;

    #[ORM\ManyToOne(targetEntity: OnlineCourse::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?OnlineCourse $course = null;

    // SET NULL rather than CASCADE: the attempts already made on the step keep their frozen
    // questions and their score, and the step then reads as unavailable instead of vanishing from
    // the follow-up of the people who validated it.
    #[ORM\ManyToOne(targetEntity: QuizTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?QuizTemplate $quizTemplate = null;

    /** The score, out of 100, the best attempt has to reach. Quiz steps only. */
    #[ORM\Column(nullable: true)]
    private ?int $passPercent = null;

    /** How many questions an attempt draws; null draws every question of the quiz. Quiz steps only. */
    #[ORM\Column(nullable: true)]
    private ?int $questionCount = null;

    private function __construct(LearningPath $path, LearningPathStepType $type)
    {
        $this->path = $path;
        $this->type = $type;
    }

    public static function forCourse(LearningPath $path, OnlineCourse $course): self
    {
        $step = new self($path, LearningPathStepType::Course);
        $step->course = $course;

        return $step;
    }

    public static function forQuiz(LearningPath $path, QuizTemplate $quiz, int $passPercent = self::DEFAULT_PASS_PERCENT, ?int $questionCount = null): self
    {
        $step = new self($path, LearningPathStepType::Quiz);
        $step->quizTemplate = $quiz;
        $step->setPassPercent($passPercent);
        $step->setQuestionCount($questionCount);

        return $step;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): LearningPath
    {
        \assert(null !== $this->path);

        return $this->path;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getType(): LearningPathStepType
    {
        return $this->type;
    }

    public function isQuiz(): bool
    {
        return LearningPathStepType::Quiz === $this->type;
    }

    public function getCourse(): ?OnlineCourse
    {
        return $this->course;
    }

    public function getQuizTemplate(): ?QuizTemplate
    {
        return $this->quizTemplate;
    }

    public function getPassPercent(): int
    {
        return $this->passPercent ?? self::DEFAULT_PASS_PERCENT;
    }

    public function setPassPercent(int $passPercent): static
    {
        $this->passPercent = max(1, min(100, $passPercent));

        return $this;
    }

    public function getQuestionCount(): ?int
    {
        return $this->questionCount;
    }

    public function setQuestionCount(?int $questionCount): static
    {
        $this->questionCount = null === $questionCount || $questionCount < 1 ? null : $questionCount;

        return $this;
    }

    /**
     * Whether the step can be followed today: its course is online, its quiz still exists and has
     * something to ask. An unavailable step is skipped, never a wall.
     */
    public function isAvailable(): bool
    {
        if ($this->isQuiz()) {
            return null !== $this->quizTemplate && !$this->quizTemplate->getQuestions()->isEmpty();
        }

        return null !== $this->course && $this->course->isPublished();
    }

    public function getTitle(): string
    {
        return ($this->isQuiz() ? $this->quizTemplate?->getName() : $this->course?->getTitle()) ?? '';
    }
}
