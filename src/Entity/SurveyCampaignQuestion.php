<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurveyCampaignQuestionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen copy of a SurveyQuestion, deep-copied by App\Service\Survey\SurveyLauncher at launch
 * time and never synced back - the mirror of QuizInstanceQuestion, minus everything about
 * correction.
 *
 * It carries one column the model does not: $comparisonKey, the sha1 of the question's shape
 * (surveys.md §7.1). A replay copies the snapshot word for word, so keys are equal *by
 * construction* - the key is not there for the normal case. It serves the one abnormal case: a
 * still-draft wave edited before opening. That question then shows up in the comparison as
 * « modifiée entre les vagues — non comparable », greyed out and out of the deltas, while every
 * other question keeps aligning. A comparison that keeps quiet about one question is worth more
 * than one that aligns two different questions.
 */
#[ORM\Entity(repositoryClass: SurveyCampaignQuestionRepository::class)]
#[ORM\Table(name: 'survey_campaign_question')]
#[ORM\Index(name: 'idx_survey_campaign_question_key', columns: ['comparison_key'])]
class SurveyCampaignQuestion implements SurveyQuestionDefinition
{
    use SurveyQuestionDefinitionTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SurveyCampaign::class, inversedBy: 'questions')]
    #[ORM\JoinColumn(name: 'survey_campaign_id', nullable: false)]
    private ?SurveyCampaign $campaign = null;

    #[ORM\Column(name: 'comparison_key', length: 40, options: ['fixed' => true])]
    private string $comparisonKey = '';

    /** @var Collection<int, SurveyCampaignAnswer> */
    #[ORM\OneToMany(mappedBy: 'question', targetEntity: SurveyCampaignAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $answers;

    public function __construct(SurveyCampaign $campaign)
    {
        $this->campaign = $campaign;
        $this->answers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): ?SurveyCampaign
    {
        return $this->campaign;
    }

    public function getComparisonKey(): string
    {
        return $this->comparisonKey;
    }

    public function setComparisonKey(string $comparisonKey): static
    {
        $this->comparisonKey = $comparisonKey;

        return $this;
    }

    /** @return Collection<int, SurveyCampaignAnswer> */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(SurveyCampaignAnswer $answer): static
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
        }

        return $this;
    }
}
