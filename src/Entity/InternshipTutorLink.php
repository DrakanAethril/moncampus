<?php

namespace App\Entity;

use App\Enum\ContractTypeCode;
use App\Repository\InternshipTutorLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Links a student to their entreprise tutor and employer for one Program's Livret Alternant.
 *
 * The tutor is a real App\Entity\User from the moment the link is created - never free text.
 * App\Service\InternshipTutorProvisioningService builds that account up front (contact e-mail,
 * ROLE_TUTOR, generated login) alongside the queued LDAP account_create request $ldapManageUser
 * points at, exactly the way DirectoryUserController::new() does for a staff-created account.
 * This used to work the other way round - four free-text tutor_* columns here, with $tutor left
 * null until the account materialised and its owner first logged in - which meant the address
 * staff typed was never anyone's contact e-mail, and mail to a tutor went somewhere no other
 * part of the app knew about.
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

    // Left nullable in PHP for the same reason as $enterprise below - the form's SUBMIT listener
    // resolves it (existing tutor picked, or a brand new account provisioned from the typed
    // contact details) just before validation runs, so this can't be a constructor argument.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'tutor_id', nullable: false)]
    #[Assert\NotNull(message: 'internshipTutorLinkTutorRequiredMessage')]
    private ?User $tutor = null;

    // Set only when this link caused a brand new account_create request to be queued (see
    // InternshipTutorProvisioningService) - null when an existing tutor was picked instead, and
    // for links created before this mechanism existed.
    #[ORM\ManyToOne(targetEntity: LdapManageUser::class)]
    #[ORM\JoinColumn(name: 'ldap_manage_user_id', nullable: true)]
    private ?LdapManageUser $ldapManageUser = null;

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

    #[ORM\Column(name: 'contract_type', length: 30, enumType: ContractTypeCode::class)]
    private ContractTypeCode $contractType = ContractTypeCode::Apprentissage;

    // The "chargé de suivi" for this alternance's livret - defaults to the Program's first
    // referent teacher at creation time (see UfaAlternanceController::createAlternance()), stored
    // per-link rather than only derived so staff can override it for one alternance without
    // affecting the Program's referent teachers. Nullable so older, pre-UFA-alternance links (and
    // links on a Program with no referent teacher yet) don't need a value.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'supervisor_id', nullable: true)]
    private ?User $supervisor = null;

    // "Alternance de test" - a fake alternance created to walk the 4-role signature flow end to
    // end without polluting real data. Ticking the box at creation time also marks whatever that
    // submission *creates* as test: the Enterprise (Enterprise::$testEnterprise, set in
    // App\Form\InternshipAlternanceType) and the tutor account, which doesn't exist yet at that
    // point - it gets flagged (User::$testUser) when the queued LDAP account materializes and its
    // owner first logs in, see App\Controller\InternshipTutorEvaluationController::home().
    // Entities merely *picked* (an existing tutor, an existing employer) are never touched.
    #[ORM\Column(name: 'test_alternance', options: ['default' => false])]
    private bool $testAlternance = false;

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

    public function getContractType(): ContractTypeCode
    {
        return $this->contractType;
    }

    public function setContractType(ContractTypeCode $contractType): static
    {
        $this->contractType = $contractType;

        return $this;
    }

    public function getSupervisor(): ?User
    {
        return $this->supervisor;
    }

    public function setSupervisor(?User $supervisor): static
    {
        $this->supervisor = $supervisor;

        return $this;
    }

    public function isTestAlternance(): bool
    {
        return $this->testAlternance;
    }

    public function setTestAlternance(bool $testAlternance): static
    {
        $this->testAlternance = $testAlternance;

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
