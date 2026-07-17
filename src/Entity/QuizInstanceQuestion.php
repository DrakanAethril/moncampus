<?php

namespace App\Entity;

use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use App\Repository\QuizInstanceQuestionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen copy of a QuizQuestion, deep-copied by App\Service\QuizInstantiationService at launch
 * time - see QuizInstance's class docblock. Never synced back to the source QuizQuestion.
 */
#[ORM\Entity(repositoryClass: QuizInstanceQuestionRepository::class)]
#[ORM\Table(name: 'quiz_instance_question')]
class QuizInstanceQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizInstance::class, inversedBy: 'questions')]
    #[ORM\JoinColumn(name: 'quiz_instance_id', nullable: false)]
    private ?QuizInstance $quizInstance = null;

    #[ORM\Column(length: 20, enumType: QuestionType::class)]
    private QuestionType $type = QuestionType::Qcm;

    #[ORM\Column(length: 20, enumType: QuestionDifficulty::class, nullable: true)]
    private ?QuestionDifficulty $difficulty = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $label = null;

    #[ORM\Column(name: 'image_storage_key', length: 255, nullable: true)]
    private ?string $imageStorageKey = null;

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    /** @var Collection<int, QuizInstanceAnswer> */
    #[ORM\OneToMany(mappedBy: 'instanceQuestion', targetEntity: QuizInstanceAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $answers;

    public function __construct(QuizInstance $quizInstance)
    {
        $this->quizInstance = $quizInstance;
        $this->answers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuizInstance(): ?QuizInstance
    {
        return $this->quizInstance;
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

    public function getOrderIndex(): int
    {
        return $this->orderIndex;
    }

    public function setOrderIndex(int $orderIndex): static
    {
        $this->orderIndex = $orderIndex;

        return $this;
    }

    /** @return Collection<int, QuizInstanceAnswer> */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(QuizInstanceAnswer $answer): static
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
        }

        return $this;
    }
}
