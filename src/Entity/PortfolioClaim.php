<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PortfolioClaimState;
use App\Repository\PortfolioClaimRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One competency a réalisation claims to mobilise, with the sentence that says how.
 *
 * The justification is mandatory: it is what the validateur reads to decide, one competency at a
 * time, whether the réalisation really shows it. Only a *retained* claim on a *validated*
 * réalisation ticks a box of the synthesis table (R5).
 */
#[ORM\Entity(repositoryClass: PortfolioClaimRepository::class)]
#[ORM\Table(name: 'portfolio_claim')]
#[ORM\UniqueConstraint(name: 'portfolio_claim_unique', columns: ['achievement_id', 'competency_id'])]
class PortfolioClaim
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PortfolioAchievement::class, inversedBy: 'claims')]
    #[ORM\JoinColumn(name: 'achievement_id', nullable: false, onDelete: 'CASCADE')]
    private ?PortfolioAchievement $achievement = null;

    #[ORM\ManyToOne(targetEntity: ReferentialCompetency::class)]
    #[ORM\JoinColumn(name: 'competency_id', nullable: false)]
    private ?ReferentialCompetency $competency = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $justification;

    #[ORM\Column(length: 20, enumType: PortfolioClaimState::class)]
    private PortfolioClaimState $state = PortfolioClaimState::Claimed;

    /** The validateur's word on this one competency, when they left one. */
    #[ORM\Column(name: 'decision_comment', type: Types::TEXT, nullable: true)]
    private ?string $decisionComment = null;

    public function __construct(PortfolioAchievement $achievement, ReferentialCompetency $competency, string $justification)
    {
        $this->achievement = $achievement;
        $this->competency = $competency;
        $this->justification = trim($justification);
        $achievement->addClaim($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAchievement(): ?PortfolioAchievement
    {
        return $this->achievement;
    }

    public function getCompetency(): ?ReferentialCompetency
    {
        return $this->competency;
    }

    public function getJustification(): string
    {
        return $this->justification;
    }

    public function setJustification(string $justification): static
    {
        $this->justification = trim($justification);

        return $this;
    }

    public function getState(): PortfolioClaimState
    {
        return $this->state;
    }

    public function isRetained(): bool
    {
        return PortfolioClaimState::Retained === $this->state;
    }

    public function getDecisionComment(): ?string
    {
        return $this->decisionComment;
    }

    public function decide(bool $retained, ?string $comment): void
    {
        $this->state = $retained ? PortfolioClaimState::Retained : PortfolioClaimState::NotRetained;
        $comment = null === $comment ? null : trim($comment);
        $this->decisionComment = '' === $comment ? null : $comment;
    }

    /** A new revision asks the question again. */
    public function reopen(): void
    {
        $this->state = PortfolioClaimState::Claimed;
    }
}
