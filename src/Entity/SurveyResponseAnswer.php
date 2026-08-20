<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurveyResponseAnswerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What one response says about one question of the snapshot (surveys.md §4).
 *
 * A row exists even when the person *skipped* the question - no selected answer, or an empty
 * free text. That is what tells « seen and passed » apart from « never reached », and what makes
 * the per-question response rate honest.
 *
 * No row is ever created for a SurveyQuestionType::Titre: it is not a question.
 *
 * $freeText is capped at 2 000 characters *and the counter is shown to the respondent* - a silent
 * truncation is a bug, and this repository has already paid for one on the wiki diagram input.
 */
#[ORM\Entity(repositoryClass: SurveyResponseAnswerRepository::class)]
#[ORM\Table(name: 'survey_response_answer')]
#[ORM\UniqueConstraint(name: 'uniq_survey_response_answer', columns: ['survey_response_id', 'survey_campaign_question_id'])]
class SurveyResponseAnswer
{
    public const int FREE_TEXT_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SurveyResponse::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(name: 'survey_response_id', nullable: false)]
    private ?SurveyResponse $response = null;

    #[ORM\ManyToOne(targetEntity: SurveyCampaignQuestion::class)]
    #[ORM\JoinColumn(name: 'survey_campaign_question_id', nullable: false)]
    private ?SurveyCampaignQuestion $question = null;

    #[ORM\Column(name: 'answered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    #[ORM\Column(name: 'free_text', length: 2000, nullable: true)]
    private ?string $freeText = null;

    /** @var Collection<int, SurveyResponseSelectedAnswer> */
    #[ORM\OneToMany(mappedBy: 'responseAnswer', targetEntity: SurveyResponseSelectedAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $selected;

    public function __construct(SurveyResponse $response, SurveyCampaignQuestion $question)
    {
        $this->response = $response;
        $this->question = $question;
        $this->selected = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResponse(): ?SurveyResponse
    {
        return $this->response;
    }

    public function getQuestion(): ?SurveyCampaignQuestion
    {
        return $this->question;
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

    public function getFreeText(): ?string
    {
        return $this->freeText;
    }

    public function setFreeText(?string $freeText): static
    {
        $this->freeText = $freeText;

        return $this;
    }

    /** @return Collection<int, SurveyResponseSelectedAnswer> */
    public function getSelected(): Collection
    {
        return $this->selected;
    }

    public function addSelected(SurveyResponseSelectedAnswer $selected): static
    {
        if (!$this->selected->contains($selected)) {
            $this->selected->add($selected);
        }

        return $this;
    }

    public function clearSelected(): static
    {
        $this->selected->clear();

        return $this;
    }

    /** Whether the respondent actually said something here, as opposed to skipping the question. */
    public function isAnswered(): bool
    {
        return !$this->selected->isEmpty() || '' !== trim((string) $this->freeText);
    }
}
