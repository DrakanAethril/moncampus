<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurveyResponseSelectedAnswerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One answer the respondent picked, and where they placed it - the exact counterpart of
 * QuizAttemptSelectedAnswer, which is where the "reuse the quiz mechanics" of surveys.md is
 * literal rather than metaphorical.
 *
 * One table for the three types that carry proposed answers:
 *  - Unique   - one row, $orderIndex is 0 and ignored;
 *  - Multiple - n rows, $orderIndex ignored;
 *  - Ordre    - one row per item, $orderIndex being the rank the respondent gave it.
 *
 * Commentaire and Titre never write a row here.
 */
#[ORM\Entity(repositoryClass: SurveyResponseSelectedAnswerRepository::class)]
#[ORM\Table(name: 'survey_response_selected_answer')]
class SurveyResponseSelectedAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SurveyResponseAnswer::class, inversedBy: 'selected')]
    #[ORM\JoinColumn(name: 'survey_response_answer_id', nullable: false)]
    private ?SurveyResponseAnswer $responseAnswer = null;

    #[ORM\ManyToOne(targetEntity: SurveyCampaignAnswer::class)]
    #[ORM\JoinColumn(name: 'survey_campaign_answer_id', nullable: false)]
    private ?SurveyCampaignAnswer $campaignAnswer = null;

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    public function __construct(SurveyResponseAnswer $responseAnswer, SurveyCampaignAnswer $campaignAnswer)
    {
        $this->responseAnswer = $responseAnswer;
        $this->campaignAnswer = $campaignAnswer;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResponseAnswer(): ?SurveyResponseAnswer
    {
        return $this->responseAnswer;
    }

    public function getCampaignAnswer(): ?SurveyCampaignAnswer
    {
        return $this->campaignAnswer;
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
