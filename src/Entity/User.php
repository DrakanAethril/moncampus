<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_username', columns: ['username'])]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $username;

    // LDAP-synced (App\Security\LdapUserMapper), overwritten on every login - the directory's
    // internal address, not necessarily reachable/monitored for real correspondence. See
    // $contactEmail for the address anything that actually sends mail should use instead.
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    // Local-only, never touched by LDAP sync - the address to actually send mail to. Distinct
    // from $email (see above) since that one is the directory's internal address, not
    // necessarily one anyone reads.
    #[ORM\Column(name: 'contact_email', length: 180, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(name: 'phone_number', length: 30, nullable: true)]
    private ?string $phoneNumber = null;

    // LDAP-synced (App\Security\LdapUserMapper) from givenName/sn, overwritten on every login -
    // kept as two separate columns rather than one displayName string so the join format
    // (getDisplayName() below) is computed here, not baked into stored data. This matters because
    // the prod Samba consumer script creates every account with cn=login (--use-username-as-cn),
    // so cn was never a safe source for a stored full-name column in the first place - givenName/
    // sn are the two attributes it reliably sets to the real name instead.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firstname = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastname = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $locale = null;

    // S3 object key under the "avatars/" prefix (see App\Service\FileUploadService), not a URL -
    // keeps the bucket/CloudFront domain changeable without a data migration.
    #[ORM\Column(name: 'avatar_key', length: 255, nullable: true)]
    private ?string $avatarKey = null;

    // LDAP-derived roles, fully overwritten on every login by App\Security\LdapUserMapper - see
    // $manualGroups for the separate, additive local grant mechanism that survives sync.
    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    // Groups staff manually assigned this user to (App\Entity\Group) - grants that role on top
    // of whatever LDAP itself grants via $roles above, and unlike $roles is never touched by
    // LDAP sync/login.
    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'user_group')]
    private Collection $manualGroups;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'inactivated_by_id', nullable: true)]
    private ?self $inactivatedBy = null;

    public function __construct(string $username)
    {
        $this->username = $username;
        $this->manualGroups = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $this->contactEmail = $contactEmail;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        if (null === $this->firstname && null === $this->lastname) {
            return null;
        }

        return trim(\sprintf('%s %s', $this->firstname ?? '', $this->lastname ?? ''));
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getAvatarKey(): ?string
    {
        return $this->avatarKey;
    }

    public function setAvatarKey(?string $avatarKey): static
    {
        $this->avatarKey = $avatarKey;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        foreach ($this->manualGroups as $group) {
            $roles[] = $group->getRole();
        }
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /** @return Collection<int, Group> */
    public function getManualGroups(): Collection
    {
        return $this->manualGroups;
    }

    public function addManualGroup(Group $group): static
    {
        if (!$this->manualGroups->contains($group)) {
            $this->manualGroups->add($group);
        }

        return $this;
    }

    public function removeManualGroup(Group $group): static
    {
        $this->manualGroups->removeElement($group);

        return $this;
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

    public function getInactivatedBy(): ?self
    {
        return $this->inactivatedBy;
    }

    public function setInactivatedBy(?self $inactivatedBy): static
    {
        $this->inactivatedBy = $inactivatedBy;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // No local credentials are stored; the password is checked against LDAP.
    }
}
