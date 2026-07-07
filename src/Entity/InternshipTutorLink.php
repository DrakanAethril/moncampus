<?php

namespace App\Entity;

use App\Repository\InternshipTutorLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Links a student to their entreprise tutor and employer for one Program's Livret Alternant.
 * The tutor doesn't have a platform account until IT provisions one under the LDAP "external"
 * group, so their contact info is kept as free text here (used for display, and to match
 * against the LDAP-provisioned account once it exists) alongside an optional link to the
 * actual User row.
 */
#[ORM\Entity(repositoryClass: InternshipTutorLinkRepository::class)]
#[ORM\Table(name: 'internship_tutor_link')]
class InternshipTutorLink
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    #[Assert\NotNull]
    private ?User $student = null;

    // Set once IT provisions the tutor's LDAP "external" account and it gets matched to this
    // link - not required to create the link itself.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'tutor_id', nullable: true)]
    private ?User $tutor = null;

    #[ORM\Column(name: 'tutor_first_name', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $tutorFirstName = '';

    #[ORM\Column(name: 'tutor_last_name', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $tutorLastName = '';

    #[ORM\Column(name: 'tutor_email', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $tutorEmail = '';

    #[ORM\Column(name: 'tutor_phone', length: 30)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 30)]
    private string $tutorPhone = '';

    #[ORM\Column(name: 'company_name', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $companyName = '';

    #[ORM\Column(name: 'company_address', type: Types::TEXT, nullable: true)]
    private ?string $companyAddress = null;

    #[ORM\Column(name: 'contract_start_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $contractStartDate = null;

    #[ORM\Column(name: 'contract_end_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $contractEndDate = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(Program $program)
    {
        $this->program = $program;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
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

    public function getTutor(): ?User
    {
        return $this->tutor;
    }

    public function setTutor(?User $tutor): static
    {
        $this->tutor = $tutor;

        return $this;
    }

    public function getTutorFirstName(): string
    {
        return $this->tutorFirstName;
    }

    public function setTutorFirstName(string $tutorFirstName): static
    {
        $this->tutorFirstName = $tutorFirstName;

        return $this;
    }

    public function getTutorLastName(): string
    {
        return $this->tutorLastName;
    }

    public function setTutorLastName(string $tutorLastName): static
    {
        $this->tutorLastName = $tutorLastName;

        return $this;
    }

    public function getTutorEmail(): string
    {
        return $this->tutorEmail;
    }

    public function setTutorEmail(string $tutorEmail): static
    {
        $this->tutorEmail = $tutorEmail;

        return $this;
    }

    public function getTutorPhone(): string
    {
        return $this->tutorPhone;
    }

    public function setTutorPhone(string $tutorPhone): static
    {
        $this->tutorPhone = $tutorPhone;

        return $this;
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function setCompanyName(string $companyName): static
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function getCompanyAddress(): ?string
    {
        return $this->companyAddress;
    }

    public function setCompanyAddress(?string $companyAddress): static
    {
        $this->companyAddress = $companyAddress;

        return $this;
    }

    public function getContractStartDate(): ?\DateTimeImmutable
    {
        return $this->contractStartDate;
    }

    public function setContractStartDate(?\DateTimeImmutable $contractStartDate): static
    {
        $this->contractStartDate = $contractStartDate;

        return $this;
    }

    public function getContractEndDate(): ?\DateTimeImmutable
    {
        return $this->contractEndDate;
    }

    public function setContractEndDate(?\DateTimeImmutable $contractEndDate): static
    {
        $this->contractEndDate = $contractEndDate;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }
}
