<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurveyTargetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person a campaign aims at, written once at launch - the pivot of the whole feature
 * (surveys.md §4).
 *
 * Three questions depend on it and on nothing else: how many people were aimed at, how many
 * answered, who to remind. Resolving the audience at display time instead would give a percentage
 * that moves on its own between two readings, and no list of non-respondents at all.
 *
 * $respondedAt is *always* nominative, even on an anonymous campaign, and that split is the whole
 * trick of surveys.md's anonymity: knowing « 18 sur 24 » and being able to remind the other 6
 * without ever being able to say who answered what. The fact of having answered lives here; the
 * content of the answer lives on SurveyResponse, whose respondent_id is null when the campaign is
 * anonymous.
 *
 * It is also what prevents a double response - not a unique index on (campaign, respondent), which
 * could not work when the respondent is null.
 */
#[ORM\Entity(repositoryClass: SurveyTargetRepository::class)]
#[ORM\Table(name: 'survey_target')]
#[ORM\UniqueConstraint(name: 'uniq_survey_target_campaign_user', columns: ['survey_campaign_id', 'user_id'])]
#[ORM\Index(name: 'idx_survey_target_campaign_responded', columns: ['survey_campaign_id', 'responded_at'])]
class SurveyTarget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SurveyCampaign::class)]
    #[ORM\JoinColumn(name: 'survey_campaign_id', nullable: false, onDelete: 'CASCADE')]
    private ?SurveyCampaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'added_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $addedAt;

    #[ORM\Column(name: 'responded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\Column(name: 'reminded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $remindedAt = null;

    public function __construct(SurveyCampaign $campaign, User $user)
    {
        $this->campaign = $campaign;
        $this->user = $user;
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): ?SurveyCampaign
    {
        return $this->campaign;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function setRespondedAt(?\DateTimeImmutable $respondedAt): static
    {
        $this->respondedAt = $respondedAt;

        return $this;
    }

    public function hasResponded(): bool
    {
        return null !== $this->respondedAt;
    }

    public function getRemindedAt(): ?\DateTimeImmutable
    {
        return $this->remindedAt;
    }

    public function setRemindedAt(?\DateTimeImmutable $remindedAt): static
    {
        $this->remindedAt = $remindedAt;

        return $this;
    }
}
