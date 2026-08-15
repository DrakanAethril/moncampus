<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    // The group this one sits inside - "SIO2 est dans SIO, qui est dans Campus". Optional (a root
    // group has none) and at most one, so the hierarchy is a tree rather than a graph. The parent
    // must be of a *different* GroupType than this group (validateParent() below): a Classe hangs
    // off a Filière, never off another Classe. Deliberately independent of LDAP - the annuaire has
    // no notion of it, so it survives a re-sync and applies to local-only groups alike.
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

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
        $this->children = new ArrayCollection();
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

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, self> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /**
     * The chain up to the root, root first, excluding the group itself - "Campus, SIO" for SIO2.
     * The object-graph twin of App\Service\GroupHierarchy::ancestorIds(), for the one caller that
     * holds a single Group rather than a screenful of them; loop-guarded for the same reason.
     *
     * @return list<self>
     */
    public function getAncestors(): array
    {
        $ancestors = [];
        $seen = [spl_object_id($this) => true];
        $current = $this->parent;

        while (null !== $current && !isset($seen[spl_object_id($current)])) {
            $seen[spl_object_id($current)] = true;
            $ancestors[] = $current;
            $current = $current->getParent();
        }

        return array_reverse($ancestors);
    }

    // "Campus › SIO › SIO2" - the one-line reading of where a group sits, for a table cell or a
    // choice label. The separator matches the breadcrumb partial's.
    public function getHierarchyPath(string $separator = ' › '): string
    {
        $names = array_map(static fn (self $group): string => $group->getName(), $this->getAncestors());
        $names[] = $this->name;

        return implode($separator, $names);
    }

    public function isDescendantOf(self $group): bool
    {
        return \in_array($group, $this->getAncestors(), true);
    }

    /**
     * The three rules of the hierarchy, checked here so they hold whatever writes the group - the
     * settings form, a future import, a command. Read them as one: a group hangs off at most one
     * other group, of a different kind, above it and never below.
     */
    #[Assert\Callback]
    public function validateParent(ExecutionContextInterface $context): void
    {
        if (null === $this->parent) {
            return;
        }

        if ($this->parent === $this) {
            $context->buildViolation('groupParentSelfMessage')->atPath('parent')->addViolation();

            return;
        }

        $typeId = $this->groupType?->getId();
        $parentTypeId = $this->parent->getGroupType()?->getId();

        // Two groups nothing distinguishes are siblings, not a level of hierarchy. An untyped group
        // under a typed one stays allowed - the types differ, which is all the rule asks.
        if (null === $typeId && null === $parentTypeId) {
            $context->buildViolation('groupParentBothUntypedMessage')->atPath('parent')->addViolation();

            return;
        }

        if ($typeId === $parentTypeId) {
            $context->buildViolation('groupParentSameTypeMessage')
                ->setParameter('%type%', $this->groupType?->getName() ?? '')
                ->atPath('parent')
                ->addViolation();

            return;
        }

        if ($this->parent->isDescendantOf($this)) {
            $context->buildViolation('groupParentCycleMessage')
                ->setParameter('%group%', $this->parent->getName())
                ->atPath('parent')
                ->addViolation();
        }
    }
}
