<?php

namespace App\Entity;

use App\Repository\QuizInstanceAnswerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen copy of a QuizAnswer - see QuizInstanceQuestion's class docblock. Same $isCorrect/
 * $orderIndex split of responsibilities as QuizAnswer (see its docblock).
 */
#[ORM\Entity(repositoryClass: QuizInstanceAnswerRepository::class)]
#[ORM\Table(name: 'quiz_instance_answer')]
class QuizInstanceAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizInstanceQuestion::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(name: 'instance_question_id', nullable: false)]
    private ?QuizInstanceQuestion $instanceQuestion = null;

    #[ORM\Column(length: 500)]
    private ?string $label = null;

    #[ORM\Column(name: 'is_correct', options: ['default' => false])]
    private bool $isCorrect = false;

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    public function __construct(QuizInstanceQuestion $instanceQuestion)
    {
        $this->instanceQuestion = $instanceQuestion;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstanceQuestion(): ?QuizInstanceQuestion
    {
        return $this->instanceQuestion;
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

    public function isCorrect(): bool
    {
        return $this->isCorrect;
    }

    public function setIsCorrect(bool $isCorrect): static
    {
        $this->isCorrect = $isCorrect;

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
}
