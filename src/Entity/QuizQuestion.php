<?php

namespace App\Entity;

use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use App\Repository\QuizQuestionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One question of a QuizTemplate's bank - screen 1b. $difficulty is nullable: an unset difficulty
 * counts as QuestionDifficulty::Moyen everywhere it's read (draw distribution, dot indicator), it
 * is not itself a "no difficulty" state that needs separate handling.
 */
#[ORM\Entity(repositoryClass: QuizQuestionRepository::class)]
#[ORM\Table(name: 'quiz_question')]
class QuizQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizTemplate::class, inversedBy: 'questions')]
    #[ORM\JoinColumn(name: 'quiz_template_id', nullable: false)]
    private ?QuizTemplate $quizTemplate = null;

    #[ORM\Column(length: 20, enumType: QuestionType::class)]
    private QuestionType $type = QuestionType::Qcm;

    #[ORM\Column(length: 20, enumType: QuestionDifficulty::class, nullable: true)]
    private ?QuestionDifficulty $difficulty = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $label = null;

    // S3 storage key (App\Service\FileUploadService), same convention as
    // MessageAttachment::$storageKey/LessonLogAttachment::$storageKey - null means no image.
    #[ORM\Column(name: 'image_storage_key', length: 255, nullable: true)]
    private ?string $imageStorageKey = null;

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    /** @var Collection<int, QuizAnswer> */
    #[ORM\OneToMany(mappedBy: 'question', targetEntity: QuizAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $answers;

    public function __construct(QuizTemplate $quizTemplate)
    {
        $this->quizTemplate = $quizTemplate;
        $this->answers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuizTemplate(): ?QuizTemplate
    {
        return $this->quizTemplate;
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

    // Never returns null - every read site treats an unset difficulty as Moyen (see class docblock).
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

    /** @return Collection<int, QuizAnswer> */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(QuizAnswer $answer): static
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
        }

        return $this;
    }

    public function removeAnswer(QuizAnswer $answer): static
    {
        $this->answers->removeElement($answer);

        return $this;
    }
}
