<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurveyCampaignAnswerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen copy of a SurveyAnswer, one level below SurveyCampaignQuestion. On a question flagged
 * is_scale, $orderIndex is the scale value, 0 being the low pole.
 */
#[ORM\Entity(repositoryClass: SurveyCampaignAnswerRepository::class)]
#[ORM\Table(name: 'survey_campaign_answer')]
class SurveyCampaignAnswer implements SurveyAnswerDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SurveyCampaignQuestion::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(name: 'survey_campaign_question_id', nullable: false)]
    private ?SurveyCampaignQuestion $question = null;

    #[ORM\Column(length: 500)]
    private string $label = '';

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    public function __construct(SurveyCampaignQuestion $question)
    {
        $this->question = $question;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): ?SurveyCampaignQuestion
    {
        return $this->question;
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
