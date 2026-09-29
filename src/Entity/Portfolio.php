<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PortfolioRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A student's portfolio of réalisations professionnelles (design/validated/portfolio.md).
 *
 * **It follows the student, not the class** (R1): one per student and référentiel, opened the
 * first time they reach « Mon portfolio » in SIO 1 and carried on in SIO 2. A App\Entity\Program
 * lives one year; a formation *enables* the portfolio and names the référentiel, it never owns one.
 *
 * `externalUrl` is the portfolio the commission actually reads - the student's own site. The
 * platform gives the commission no access at all (§1), so an E5 cannot be deposited without it.
 */
#[ORM\Entity(repositoryClass: PortfolioRepository::class)]
#[ORM\Table(name: 'portfolio')]
#[ORM\UniqueConstraint(name: 'portfolio_student_referential_unique', columns: ['student_id', 'referential_id'])]
class Portfolio
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?User $student = null;

    #[ORM\ManyToOne(targetEntity: Referential::class)]
    #[ORM\JoinColumn(name: 'referential_id', nullable: false)]
    private ?Referential $referential = null;

    #[ORM\Column(name: 'candidate_number', length: 30, nullable: true)]
    private ?string $candidateNumber = null;

    #[ORM\Column(name: 'external_url', length: 500, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, PortfolioAchievement> */
    #[ORM\OneToMany(mappedBy: 'portfolio', targetEntity: PortfolioAchievement::class)]
    #[ORM\OrderBy(['startsOn' => 'ASC', 'id' => 'ASC'])]
    private Collection $achievements;

    /** @var Collection<int, PortfolioShowcase> */
    #[ORM\OneToMany(mappedBy: 'portfolio', targetEntity: PortfolioShowcase::class)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $showcases;

    /** @var Collection<int, PortfolioEvidence> the internship attestations of the E5 dossier */
    #[ORM\OneToMany(mappedBy: 'portfolio', targetEntity: PortfolioEvidence::class)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $attestations;

    public function __construct(User $student, Referential $referential)
    {
        $this->student = $student;
        $this->referential = $referential;
        $this->createdAt = new \DateTimeImmutable();
        $this->achievements = new ArrayCollection();
        $this->showcases = new ArrayCollection();
        $this->attestations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getReferential(): ?Referential
    {
        return $this->referential;
    }

    public function getCandidateNumber(): ?string
    {
        return $this->candidateNumber;
    }

    public function setCandidateNumber(?string $candidateNumber): static
    {
        $this->candidateNumber = null === $candidateNumber || '' === trim($candidateNumber) ? null : trim($candidateNumber);

        return $this;
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function setExternalUrl(?string $externalUrl): static
    {
        $this->externalUrl = null === $externalUrl || '' === trim($externalUrl) ? null : trim($externalUrl);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, PortfolioAchievement> */
    public function getAchievements(): Collection
    {
        return $this->achievements;
    }

    public function addAchievement(PortfolioAchievement $achievement): static
    {
        if (!$this->achievements->contains($achievement)) {
            $this->achievements->add($achievement);
        }

        return $this;
    }

    /** @return Collection<int, PortfolioShowcase> */
    public function getShowcases(): Collection
    {
        return $this->showcases;
    }

    public function addShowcase(PortfolioShowcase $showcase): static
    {
        if (!$this->showcases->contains($showcase)) {
            $this->showcases->add($showcase);
        }

        return $this;
    }

    public function getShowcase(int $number): ?PortfolioShowcase
    {
        foreach ($this->showcases as $showcase) {
            if ($showcase->getNumber() === $number) {
                return $showcase;
            }
        }

        return null;
    }

    /** @return Collection<int, PortfolioEvidence> */
    public function getAttestations(): Collection
    {
        return $this->attestations;
    }

    public function addAttestation(PortfolioEvidence $evidence): static
    {
        if (!$this->attestations->contains($evidence)) {
            $this->attestations->add($evidence);
        }

        return $this;
    }

    public function removeAttestation(PortfolioEvidence $evidence): static
    {
        $this->attestations->removeElement($evidence);

        return $this;
    }
}
