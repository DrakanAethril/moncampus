<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurveyAnswerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One answer offered by a SurveyQuestion. No is_correct, no points, no weight: a survey never
 * grades anything (surveys.md §1).
 *
 * On a question flagged is_scale, $orderIndex *is* the scale value, 0 being the low pole.
 */
#[ORM\Entity(repositoryClass: SurveyAnswerRepository::class)]
#[ORM\Table(name: 'survey_answer')]
class SurveyAnswer implements SurveyAnswerDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SurveyQuestion::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(name: 'survey_question_id', nullable: false)]
    private ?SurveyQuestion $surveyQuestion = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    private string $label = '';

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    public function __construct(SurveyQuestion $surveyQuestion)
    {
        $this->surveyQuestion = $surveyQuestion;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSurveyQuestion(): ?SurveyQuestion
    {
        return $this->surveyQuestion;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

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
