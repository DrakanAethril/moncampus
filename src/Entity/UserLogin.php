<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserLoginRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One login a user has held - the current one, or one they held before a rename.
 *
 * `User::$username` stays the truth about *now*: the user provider looks accounts up by it and
 * LdapCredentialsVerifier searches the directory by it, and nothing here changes that. This table
 * answers the other two questions, which nothing could answer before:
 *
 *  - **what has this account been called?** A rename used to overwrite `User::$username` and the
 *    previous login survived nowhere. `ldap_manage_account` records the *request*, not the login it
 *    replaced, and a second rename left the intermediate one with no trace at all.
 *  - **may somebody else be called that?** No, never - and the `UNIQUE` on `login` is what says so,
 *    rather than a check some future caller could forget. A login is burnt the moment it is
 *    assigned: mail has been sent from it, a home directory on the file server was named after it,
 *    and handing it to a second person would silently give them the first one's past.
 *
 * The symmetry the rule needs is that **the same person may take their own old login back**. That
 * falls out of the shape rather than being a special case: coming back to a login one already holds
 * a row for is clearing its `releasedAt`, not inserting anything, so the unique index is never in
 * the way. App\Service\UserLoginHistory::record() is the single writer.
 *
 * `assignedAt` is nullable on purpose: the migration that created this table backfilled every
 * account that already existed, and for most of them the date a login was taken is simply not
 * recorded anywhere. A null there means "before we started counting", which is honest; a fabricated
 * date would not be.
 */
#[ORM\Entity(repositoryClass: UserLoginRepository::class)]
#[ORM\Table(name: 'user_login')]
#[ORM\UniqueConstraint(name: 'uniq_user_login_login', columns: ['login'])]
#[ORM\Index(name: 'idx_user_login_user', columns: ['user_id'])]
class UserLogin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'loginHistory')]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    // 64, the same width as User::$username and the upper bound of LOGIN_PATTERN.
    #[ORM\Column(length: 64)]
    private string $login;

    #[ORM\Column(name: 'assigned_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    /** Null on the login the account answers to today - there is exactly one such row per user. */
    #[ORM\Column(name: 'released_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    public function __construct(User $user, string $login, ?\DateTimeImmutable $assignedAt = null)
    {
        $this->user = $user;
        $this->login = $login;
        $this->assignedAt = $assignedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getAssignedAt(): ?\DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function setAssignedAt(?\DateTimeImmutable $assignedAt): static
    {
        $this->assignedAt = $assignedAt;

        return $this;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function setReleasedAt(?\DateTimeImmutable $releasedAt): static
    {
        $this->releasedAt = $releasedAt;

        return $this;
    }

    public function isCurrent(): bool
    {
        return null === $this->releasedAt;
    }
}
