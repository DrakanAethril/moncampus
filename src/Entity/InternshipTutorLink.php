<?php

namespace App\Entity;

use App\Repository\InternshipTutorLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Links a student to their entreprise tutor and employer for one Program's Livret Alternant.
 * The tutor doesn't have a platform account yet when this link is created - their contact info
 * is kept as free text here, and $ldapManageUser optionally points at the queued account-creation
 * request this link spawned (see App\Service\InternshipTutorProvisioningService). $tutor itself
 * stays null until the account materializes and its owner logs in for the first time, matched
 * opportunistically either by tutorEmail or by the login the consumer script generated for
 * $ldapManageUser (see App\Controller\InternshipTutorEvaluationController::home()).
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

    // Set once the tutor's LDAP "external" account gets matched to this link - not required to
    // create the link itself.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'tutor_id', nullable: true)]
    private ?User $tutor = null;

    // Set only when this link caused a brand new account_create request to be queued (see
    // InternshipTutorProvisioningService) - null if the tutor already had an account or a
    // pending request from another link with the same tutorEmail, or for links created before
    // this mechanism existed.
    #[ORM\ManyToOne(targetEntity: LdapManageUser::class)]
    #[ORM\JoinColumn(name: 'ldap_manage_user_id', nullable: true)]
    private ?LdapManageUser $ldapManageUser = null;

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

    // Not nullable at the DB/business level, but left nullable in PHP so the controller can
    // resolve/create the Enterprise (existing pick or inline new one) after form validation has
    // already run - see ProgramInternshipController::tutorLinkForm().
    #[ORM\ManyToOne(targetEntity: Enterprise::class)]
    #[ORM\JoinColumn(name: 'enterprise_id', nullable: false)]
    #[Assert\NotNull(message: 'internshipTutorLinkEnterpriseRequiredMessage')]
    private ?Enterprise $enterprise = null;

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

    public function getLdapManageUser(): ?LdapManageUser
    {
        return $this->ldapManageUser;
    }

    public function setLdapManageUser(?LdapManageUser $ldapManageUser): static
    {
        $this->ldapManageUser = $ldapManageUser;

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

    public function getEnterprise(): ?Enterprise
    {
        return $this->enterprise;
    }

    public function setEnterprise(?Enterprise $enterprise): static
    {
        $this->enterprise = $enterprise;

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
