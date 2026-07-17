<?php

namespace App\Entity;

use App\Repository\QuizAttemptAnswerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One drawn question within a QuizAttempt, at this student's own presentation position
 * ($orderIndex - see App\Service\QuizDrawService). Created for all of an attempt's questions up
 * front (screen 1e navigates between already-existing rows rather than creating them on the fly),
 * so "the current question" is simply the first one with $answeredAt still null. Autosaved on
 * "Question suivante" - see QuizAttempt's class docblock for why this matters (a crashed browser
 * shouldn't lose progress on earlier questions).
 */
#[ORM\Entity(repositoryClass: QuizAttemptAnswerRepository::class)]
#[ORM\Table(name: 'quiz_attempt_answer')]
class QuizAttemptAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizAttempt::class, inversedBy: 'attemptAnswers')]
    #[ORM\JoinColumn(name: 'attempt_id', nullable: false)]
    private ?QuizAttempt $attempt = null;

    #[ORM\ManyToOne(targetEntity: QuizInstanceQuestion::class)]
    #[ORM\JoinColumn(name: 'instance_question_id', nullable: false)]
    private ?QuizInstanceQuestion $instanceQuestion = null;

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    #[ORM\Column(name: 'answered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    // Computed and frozen the moment this question is answered (App\Service\QuizAttemptGrader) -
    // not recomputed at correction-display time, so a later change to how grading works never
    // silently reshuffles an already-answered attempt's outcome.
    #[ORM\Column(name: 'is_correct', nullable: true)]
    private ?bool $isCorrect = null;

    /** @var Collection<int, QuizAttemptSelectedAnswer> */
    #[ORM\OneToMany(mappedBy: 'attemptAnswer', targetEntity: QuizAttemptSelectedAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $selectedAnswers;

    public function __construct(QuizAttempt $attempt, QuizInstanceQuestion $instanceQuestion)
    {
        $this->attempt = $attempt;
        $this->instanceQuestion = $instanceQuestion;
        $this->selectedAnswers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAttempt(): ?QuizAttempt
    {
        return $this->attempt;
    }

    public function getInstanceQuestion(): ?QuizInstanceQuestion
    {
        return $this->instanceQuestion;
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

    public function getAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function setAnsweredAt(?\DateTimeImmutable $answeredAt): static
    {
        $this->answeredAt = $answeredAt;

        return $this;
    }

    public function isAnswered(): bool
    {
        return null !== $this->answeredAt;
    }

    public function getIsCorrect(): ?bool
    {
        return $this->isCorrect;
    }

    public function setIsCorrect(?bool $isCorrect): static
    {
        $this->isCorrect = $isCorrect;

        return $this;
    }

    /** @return Collection<int, QuizAttemptSelectedAnswer> */
    public function getSelectedAnswers(): Collection
    {
        return $this->selectedAnswers;
    }

    public function addSelectedAnswer(QuizAttemptSelectedAnswer $selectedAnswer): static
    {
        if (!$this->selectedAnswers->contains($selectedAnswer)) {
            $this->selectedAnswers->add($selectedAnswer);
        }

        return $this;
    }

    public function removeSelectedAnswer(QuizAttemptSelectedAnswer $selectedAnswer): static
    {
        $this->selectedAnswers->removeElement($selectedAnswer);

        return $this;
    }
}
