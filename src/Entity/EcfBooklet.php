<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EcfCivility;
use App\Repository\EcfBookletRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One candidate's livret d'évaluations passées en cours de formation for one titre professionnel
 * (design/validated/ecf-booklet.md).
 *
 * It follows the candidate and the titre, not the Program: the titre spans two school years and a
 * Program lives one. Its activity-types are never stored here - they are read from the formation's
 * competency groups (App\Service\Ecf\EcfActivityTypes); an EcfActivity row only exists once
 * something has been written for one.
 */
#[ORM\Entity(repositoryClass: EcfBookletRepository::class)]
#[ORM\Table(name: 'ecf_booklet')]
#[ORM\UniqueConstraint(name: 'uniq_ecf_booklet_student_title', columns: ['student_id', 'title_code', 'millesime'])]
class EcfBooklet
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(name: 'title_code', length: 30)]
    private string $titleCode;

    #[ORM\Column(length: 10)]
    private string $millesime;

    #[ORM\Column(length: 10, nullable: true, enumType: EcfCivility::class)]
    private ?EcfCivility $civility = null;

    // Asked by the cover, and kept nowhere else on the platform.
    #[ORM\Column(name: 'birth_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(name: 'synthesis_observations', type: Types::TEXT, nullable: true)]
    private ?string $synthesisObservations = null;

    // « Un exemplaire du livret a été remis au candidat … le » - the candidate signs the printed
    // copy by hand: they have no access to the booklet.
    #[ORM\Column(name: 'remitted_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $remittedOn = null;

    // Set by the representative's visa on the synthesis, cleared when it is taken back.
    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, EcfActivity> */
    #[ORM\OneToMany(targetEntity: EcfActivity::class, mappedBy: 'booklet', cascade: ['persist', 'remove'])]
    private Collection $activities;

    /** @var Collection<int, EcfVisa> */
    #[ORM\OneToMany(targetEntity: EcfVisa::class, mappedBy: 'booklet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $visas;

    public function __construct(User $student, string $titleCode, string $millesime)
    {
        $this->student = $student;
        $this->titleCode = $titleCode;
        $this->millesime = $millesime;
        $this->createdAt = new \DateTimeImmutable();
        $this->activities = new ArrayCollection();
        $this->visas = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): User
    {
        return $this->student;
    }

    public function getTitleCode(): string
    {
        return $this->titleCode;
    }

    public function getMillesime(): string
    {
        return $this->millesime;
    }

    public function getCivility(): ?EcfCivility
    {
        return $this->civility;
    }

    public function setCivility(?EcfCivility $civility): static
    {
        $this->civility = $civility;

        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    public function getSynthesisObservations(): ?string
    {
        return $this->synthesisObservations;
    }

    public function setSynthesisObservations(?string $synthesisObservations): static
    {
        $this->synthesisObservations = $synthesisObservations;

        return $this;
    }

    public function getRemittedOn(): ?\DateTimeImmutable
    {
        return $this->remittedOn;
    }

    public function setRemittedOn(?\DateTimeImmutable $remittedOn): static
    {
        $this->remittedOn = $remittedOn;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function isClosed(): bool
    {
        return null !== $this->closedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, EcfActivity> */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    public function activityFor(string $groupCode): ?EcfActivity
    {
        foreach ($this->activities as $activity) {
            if ($activity->getGroupCode() === $groupCode) {
                return $activity;
            }
        }

        return null;
    }

    public function addActivity(EcfActivity $activity): static
    {
        if (!$this->activities->contains($activity)) {
            $this->activities->add($activity);
        }

        return $this;
    }

    /** @return Collection<int, EcfVisa> */
    public function getVisas(): Collection
    {
        return $this->visas;
    }

    public function addVisa(EcfVisa $visa): static
    {
        if (!$this->visas->contains($visa)) {
            $this->visas->add($visa);
        }

        return $this;
    }

    public function removeVisa(EcfVisa $visa): static
    {
        $this->visas->removeElement($visa);

        return $this;
    }
}
