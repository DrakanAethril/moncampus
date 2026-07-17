<?php

namespace App\Entity;

use App\Repository\QuizAnswerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One answer option of a QuizQuestion. $isCorrect marks the/a correct answer for
 * qcm/qcm_multi/vrai_faux/image questions; for an "ordre" question, $orderIndex instead holds the
 * correct sequence position and $isCorrect is unused - see App\Enum\QuestionType.
 */
#[ORM\Entity(repositoryClass: QuizAnswerRepository::class)]
#[ORM\Table(name: 'quiz_answer')]
class QuizAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizQuestion::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(name: 'question_id', nullable: false)]
    private ?QuizQuestion $question = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    private ?string $label = null;

    #[ORM\Column(name: 'is_correct', options: ['default' => false])]
    private bool $isCorrect = false;

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    public function __construct(QuizQuestion $question)
    {
        $this->question = $question;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): ?QuizQuestion
    {
        return $this->question;
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
