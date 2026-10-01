<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HostingKind;
use App\Enum\HostingSource;
use App\Repository\EnterpriseHostingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One of our students at a company: a **stage** or an **alternance**, a school year, a filière -
 * the vivier's memory (design/validated/vivier-entreprises.md §5.2).
 *
 * The alternances the UFA manages are **not** stored here: they are read from their contracts
 * (InternshipTutorLink) and never copied (R3). A row holds an alternance only for a year the UFA
 * has no trace of - the history typed or imported - and App\Service\EnterprisePool\EnterpriseHostings
 * is the one reading that puts both together.
 *
 * The student is an account when there is one, a name otherwise: a former student of 2014 has no
 * account here, and the history is about the company, not about them. Only an administrator ever
 * reads either (§3).
 *
 * Withdrawn rather than deleted (R9): `inactive_date`, like every structure row of the platform.
 */
#[ORM\Entity(repositoryClass: EnterpriseHostingRepository::class)]
#[ORM\Table(name: 'enterprise_hosting')]
#[ORM\Index(name: 'idx_enterprise_hosting_enterprise', columns: ['enterprise_id'])]
class EnterpriseHosting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Enterprise::class)]
    #[ORM\JoinColumn(name: 'enterprise_id', nullable: false, onDelete: 'CASCADE')]
    private ?Enterprise $enterprise = null;

    /** Never defaulted: guessing stage or alternance is exactly what D2 forbids. */
    #[ORM\Column(length: 20, enumType: HostingKind::class)]
    #[Assert\NotNull(message: 'enterpriseHostingKindRequiredError')]
    private ?HostingKind $kind = null;

    /** The school year by its first calendar year: 2023 for 2023-2024. */
    #[ORM\Column(name: 'year_start')]
    #[Assert\Range(min: 1990, max: 2100)]
    private int $yearStart = 0;

    #[ORM\ManyToOne(targetEntity: Track::class)]
    #[ORM\JoinColumn(name: 'track_id', nullable: false)]
    #[Assert\NotNull(message: 'enterpriseHostingTrackRequiredError')]
    private ?Track $track = null;

    #[ORM\ManyToOne(targetEntity: Option::class)]
    #[ORM\JoinColumn(name: 'option_id', nullable: true, onDelete: 'SET NULL')]
    private ?Option $option = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $student = null;

    #[ORM\Column(name: 'student_name', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $studentName = null;

    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $missions = null;

    /** The tutor of this stage, when one is known. */
    #[ORM\ManyToOne(targetEntity: EnterpriseContact::class)]
    #[ORM\JoinColumn(name: 'contact_id', nullable: true, onDelete: 'SET NULL')]
    private ?EnterpriseContact $contact = null;

    #[ORM\Column(length: 20, enumType: HostingSource::class)]
    private HostingSource $source = HostingSource::Manual;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'inactivated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $inactivatedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateStudent(ExecutionContextInterface $context): void
    {
        if (null === $this->student && null === $this->studentName) {
            $context->buildViolation('enterpriseHostingStudentRequiredError')->atPath('student')->addViolation();
        }

        if (null !== $this->startDate && null !== $this->endDate && $this->endDate < $this->startDate) {
            $context->buildViolation('enterpriseHostingDatesOrderError')->atPath('endDate')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnterprise(): ?Enterprise
    {
        return $this->enterprise;
    }

    public function setEnterprise(?Enterprise $enterprise): static
    {
        $this->enterprise = $enterprise;

        return $this;
    }

    public function getKind(): ?HostingKind
    {
        return $this->kind;
    }

    public function setKind(?HostingKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getYearStart(): int
    {
        return $this->yearStart;
    }

    public function setYearStart(int $yearStart): static
    {
        $this->yearStart = $yearStart;

        return $this;
    }

    public function getTrack(): ?Track
    {
        return $this->track;
    }

    public function setTrack(?Track $track): static
    {
        $this->track = $track;

        return $this;
    }

    public function getOption(): ?Option
    {
        return $this->option;
    }

    public function setOption(?Option $option): static
    {
        $this->option = $option;

        return $this;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function setStudent(?User $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getStudentName(): ?string
    {
        return $this->studentName;
    }

    public function setStudentName(?string $studentName): static
    {
        $studentName = null !== $studentName ? trim($studentName) : null;
        $this->studentName = '' !== $studentName ? $studentName : null;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getMissions(): ?string
    {
        return $this->missions;
    }

    public function setMissions(?string $missions): static
    {
        $missions = null !== $missions ? trim($missions) : null;
        $this->missions = '' !== $missions ? $missions : null;

        return $this;
    }

    public function getContact(): ?EnterpriseContact
    {
        return $this->contact;
    }

    public function setContact(?EnterpriseContact $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getSource(): HostingSource
    {
        return $this->source;
    }

    public function setSource(HostingSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(User $by): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedBy = $by;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function withdraw(User $by): static
    {
        $this->inactiveDate = new \DateTimeImmutable();
        $this->inactivatedBy = $by;

        return $this;
    }

    public function getInactivatedBy(): ?User
    {
        return $this->inactivatedBy;
    }
}
