<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Repository\UserRepository;
use App\State\CurrentUserProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

// Only a single custom "/me" operation is exposed for now (the mobile app's home screen), not
// the full CRUD set API Platform would otherwise generate - there's no product need yet for the
// API to browse/edit arbitrary users, and exposing that surface prematurely would need its own
// access-control review. See CurrentUserProvider: "me" has no id in the URL, it's resolved from
// whichever User the api firewall's JWT authenticated as.
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/me',
            security: "is_granted('ROLE_USER')",
            provider: CurrentUserProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['user:read']],
)]
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_username', columns: ['username'])]
#[ORM\UniqueConstraint(name: 'uniq_user_contact_email', columns: ['contact_email'])]
#[UniqueEntity(fields: ['contactEmail'], message: 'contactEmailAlreadyUsedMessage', ignoreNull: true)]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Groups(['user:read'])]
    private string $username;

    // LDAP-synced (App\Security\LdapUserMapper), overwritten on every login - the directory's
    // internal address, not necessarily reachable/monitored for real correspondence. See
    // $contactEmail for the address anything that actually sends mail should use instead.
    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['user:read'])]
    private ?string $email = null;

    // Local-only, never touched by LDAP sync - the address to actually send mail to. Distinct
    // from $email (see above) since that one is the directory's internal address, not
    // necessarily one anyone reads.
    #[ORM\Column(name: 'contact_email', length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $contactEmail = null;

    // Set once the current $contactEmail value has been confirmed via the mailed link
    // (App\Service\ContactEmailVerifier) - reset to null any time $contactEmail changes, so a
    // stale value can never be mistaken for proof of the new address.
    #[ORM\Column(name: 'contact_email_verified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contactEmailVerifiedAt = null;

    // Pending confirmation token (App\Service\ContactEmailVerifier) - null whenever nothing is
    // awaiting confirmation (either verified already, or no contact email set).
    #[ORM\Column(name: 'contact_email_token', length: 64, nullable: true, unique: true)]
    private ?string $contactEmailToken = null;

    // When $contactEmailToken was generated - drives both the link's 24h expiry and the resend
    // cooldown (App\Service\ContactEmailVerifier).
    #[ORM\Column(name: 'contact_email_token_requested_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contactEmailTokenRequestedAt = null;

    #[ORM\Column(name: 'phone_number', length: 30, nullable: true)]
    private ?string $phoneNumber = null;

    // LDAP-synced (App\Security\LdapUserMapper) from givenName/sn, overwritten on every login -
    // kept as two separate columns rather than one displayName string so the join format
    // (getDisplayName() below) is computed here, not baked into stored data. This matters because
    // the prod Samba consumer script creates every account with cn=login (--use-username-as-cn),
    // so cn was never a safe source for a stored full-name column in the first place - givenName/
    // sn are the two attributes it reliably sets to the real name instead.
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read'])]
    private ?string $firstname = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read'])]
    private ?string $lastname = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $locale = null;

    // UI preference (App\Controller\ProfileController), not LDAP-sourced - defaults to 'dark'
    // since that's the app's own default theme (see templates/base.html.twig). The only other
    // valid value is 'light'.
    #[ORM\Column(name: 'theme_preference', length: 5, options: ['default' => 'dark'])]
    private string $themePreference = 'dark';

    // S3 object key under the "avatars/" prefix (see App\Service\FileUploadService), not a URL -
    // keeps the bucket/CloudFront domain changeable without a data migration.
    #[ORM\Column(name: 'avatar_key', length: 255, nullable: true)]
    private ?string $avatarKey = null;

    // Whether an internal message received by this user (App\Controller\MessageController) also
    // gets forwarded to their $contactEmail, provided it's verified - see
    // App\Service\MessageEmailNotifier. Defaults to true to match the reference design's toggle.
    #[ORM\Column(name: 'email_copy_of_messages_enabled', options: ['default' => true])]
    private bool $emailCopyOfMessagesEnabled = true;

    // HugeRTE-authored HTML (sanitized via the "app.message_signature" sanitizer, a stricter
    // subset than $body's "app.message_body" one - see config/packages/html_sanitizer.yaml),
    // appended to the email copy of messages this user sends (never shown inside the app's own
    // messaging UI, see App\Service\MessageEmailNotifier).
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signature = null;

    // LDAP-derived roles, fully overwritten on every login by App\Security\LdapUserMapper - see
    // $manualGroups for the separate, additive local grant mechanism that survives sync.
    /** @var list<string> */
    #[ORM\Column]
    #[Groups(['user:read'])]
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

    public function isContactEmailVerified(): bool
    {
        return null !== $this->contactEmail && null !== $this->contactEmailVerifiedAt;
    }

    public function getContactEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->contactEmailVerifiedAt;
    }

    public function setContactEmailVerifiedAt(?\DateTimeImmutable $contactEmailVerifiedAt): static
    {
        $this->contactEmailVerifiedAt = $contactEmailVerifiedAt;

        return $this;
    }

    public function getContactEmailToken(): ?string
    {
        return $this->contactEmailToken;
    }

    public function setContactEmailToken(?string $contactEmailToken): static
    {
        $this->contactEmailToken = $contactEmailToken;

        return $this;
    }

    public function getContactEmailTokenRequestedAt(): ?\DateTimeImmutable
    {
        return $this->contactEmailTokenRequestedAt;
    }

    public function setContactEmailTokenRequestedAt(?\DateTimeImmutable $contactEmailTokenRequestedAt): static
    {
        $this->contactEmailTokenRequestedAt = $contactEmailTokenRequestedAt;

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

    // A less identifying variant ("S. Tharaud" instead of "Sébastien Tharaud") for contexts that
    // shouldn't expose a person's full first name (e.g. a teacher's name shown to students on the
    // Program teachers list) - kept as its own method, not a parameter on getDisplayName(), so
    // call sites can switch which one they want without threading a flag through every caller.
    public function getPoliteDisplayName(): ?string
    {
        if (null === $this->firstname) {
            return $this->lastname;
        }

        return trim(\sprintf('%s. %s', mb_strtoupper(mb_substr($this->firstname, 0, 1)), $this->lastname ?? ''));
    }

    // Two-letter avatar-placeholder initials ("ST" for Sébastien Tharaud) - null only when
    // neither name part is known at all, letting callers fall back to a single username-derived
    // letter (see templates/program/_user_card.html.twig).
    public function getInitials(): ?string
    {
        if (null === $this->firstname && null === $this->lastname) {
            return null;
        }

        $firstLetter = null !== $this->firstname ? mb_strtoupper(mb_substr($this->firstname, 0, 1)) : '';
        $lastLetter = null !== $this->lastname ? mb_strtoupper(mb_substr($this->lastname, 0, 1)) : '';

        return $firstLetter.$lastLetter;
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

    public function getThemePreference(): string
    {
        return $this->themePreference;
    }

    public function setThemePreference(string $themePreference): static
    {
        $this->themePreference = $themePreference;

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

    public function isEmailCopyOfMessagesEnabled(): bool
    {
        return $this->emailCopyOfMessagesEnabled;
    }

    public function setEmailCopyOfMessagesEnabled(bool $emailCopyOfMessagesEnabled): static
    {
        $this->emailCopyOfMessagesEnabled = $emailCopyOfMessagesEnabled;

        return $this;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): static
    {
        $this->signature = $signature;

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
