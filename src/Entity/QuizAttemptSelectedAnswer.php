<?php

namespace App\Entity;

use App\Repository\QuizAttemptSelectedAnswerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One answer the student picked for a QuizAttemptAnswer - a plain join row for qcm/vrai_faux/image
 * (exactly one), qcm_multi (zero or more, unordered), or the student's submitted sequence for an
 * "ordre" question ($orderIndex is the position they placed it at, not the answer's true/correct
 * position - see App\Entity\QuizAnswer's docblock on that split).
 */
#[ORM\Entity(repositoryClass: QuizAttemptSelectedAnswerRepository::class)]
#[ORM\Table(name: 'quiz_attempt_selected_answer')]
class QuizAttemptSelectedAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizAttemptAnswer::class, inversedBy: 'selectedAnswers')]
    #[ORM\JoinColumn(name: 'attempt_answer_id', nullable: false)]
    private ?QuizAttemptAnswer $attemptAnswer = null;

    #[ORM\ManyToOne(targetEntity: QuizInstanceAnswer::class)]
    #[ORM\JoinColumn(name: 'instance_answer_id', nullable: false)]
    private ?QuizInstanceAnswer $instanceAnswer = null;

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    public function __construct(QuizAttemptAnswer $attemptAnswer, QuizInstanceAnswer $instanceAnswer)
    {
        $this->attemptAnswer = $attemptAnswer;
        $this->instanceAnswer = $instanceAnswer;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAttemptAnswer(): ?QuizAttemptAnswer
    {
        return $this->attemptAnswer;
    }

    public function getInstanceAnswer(): ?QuizInstanceAnswer
    {
        return $this->instanceAnswer;
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
}
