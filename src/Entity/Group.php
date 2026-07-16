<?php

namespace App\Entity;

use App\Repository\GroupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A role-granting group - either mirrored from a real LDAP group (ldapCn set, upserted by
 * App\Security\LdapUserMapper on login and App\Service\LdapGroupSyncer's manual sync) or created
 * directly here for a role that doesn't correspond to anything in LDAP (ldapCn null). Either way,
 * a user only actually gets the role via a manual assignment (User::$manualGroups) - membership
 * derived live from real LDAP group membership at login time (App\Security\LdapUserMapper)
 * remains a completely separate mechanism this table doesn't replace, only supplements.
 */
#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table(name: '`group`')]
#[ORM\UniqueConstraint(name: 'group_ldap_cn_unique', columns: ['ldap_cn'])]
class Group
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    // Set only for groups mirrored from LDAP - this is the LDAP group's own cn, matched on to
    // avoid creating duplicate rows for the same LDAP group. Null for a group created directly
    // here, which has no LDAP counterpart at all.
    #[ORM\Column(name: 'ldap_cn', length: 255, nullable: true)]
    private ?string $ldapCn = null;

    // Purely a display grouping (see GroupType's own docblock) - optional, LDAP-or-not alike.
    #[ORM\ManyToOne(targetEntity: GroupType::class)]
    #[ORM\JoinColumn(name: 'group_type_id', nullable: true)]
    private ?GroupType $groupType = null;

    // The ROLE_X granted to a user manually assigned to this group - free text (validated as a
    // ROLE_ prefix, not restricted to the app's existing fixed role vocabulary), since the whole
    // point of a locally-created group is introducing a role that doesn't exist elsewhere yet.
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^ROLE_[A-Z0-9_-]+$/', message: 'groupRoleFormatMessage')]
    private string $role;

    // Whether staff can manually assign a user to this group (User::$manualGroups) - always true
    // in practice for a local-only group (it's the only way to belong to one), defaults false
    // for a freshly LDAP-mirrored group so LDAP stays authoritative unless someone opts in.
    #[ORM\Column(name: 'manually_assignable')]
    private bool $manuallyAssignable;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $name, string $role, ?string $ldapCn = null, bool $manuallyAssignable = false)
    {
        $this->name = $name;
        $this->role = $role;
        $this->ldapCn = $ldapCn;
        $this->manuallyAssignable = $manuallyAssignable;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLdapCn(): ?string
    {
        return $this->ldapCn;
    }

    public function isLdapSynced(): bool
    {
        return null !== $this->ldapCn;
    }

    public function getGroupType(): ?GroupType
    {
        return $this->groupType;
    }

    public function setGroupType(?GroupType $groupType): static
    {
        $this->groupType = $groupType;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function isManuallyAssignable(): bool
    {
        return $this->manuallyAssignable;
    }

    public function setManuallyAssignable(bool $manuallyAssignable): static
    {
        $this->manuallyAssignable = $manuallyAssignable;

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
