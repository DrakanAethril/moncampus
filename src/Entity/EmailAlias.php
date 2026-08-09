<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EmailAliasOrigin;
use App\Repository\EmailAliasRepository;
use App\Util\SchoolMailLocalPart;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One address local part (what comes before the `@`) attached to a student. The domain is not
 * stored: it comes from MAIL_STUDENT_DOMAIN, which lets the same database work in dev
 * (`devetu.beaupeyrat.org`) and in production (`etu.beaupeyrat.org`).
 *
 * An indispensable table, and not merely for naming comfort: SES reception is catch-all, so the
 * worker receives *any* address on the domain and must resolve its owner by matching, never by
 * guessing from the login.
 *
 * Several active aliases per student is the normal case, not the exception:
 * - `firstname.lastname` is the displayed and sending address;
 * - the LDAP login (`croux`) is kept as a permanent alias, so both work;
 * - a change of civil status adds an address without ever removing the old one, which stays printed
 *   on CVs that reached companies and has to keep delivering.
 *
 * Which one is "the" student's address - the one displayed and written from - is not read here but
 * on App\Entity\User::$primaryAlias. It is a single-valued fact about the student, not a property
 * of each alias: keeping it on User makes the "only one primary address" invariant true by
 * construction, where a flag spread over N rows would have required a partial unique index MySQL
 * cannot express.
 */
#[ORM\Entity(repositoryClass: EmailAliasRepository::class)]
#[ORM\Table(name: 'email_alias')]
#[ORM\UniqueConstraint(name: 'uniq_email_alias_local_part', columns: ['local_part'])]
#[ORM\Index(name: 'idx_email_alias_user', columns: ['user_id'])]
class EmailAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // 64 characters: the local-part limit set by RFC 5321.
    #[ORM\Column(name: 'local_part', length: 64)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private string $localPart;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'emailAliases')]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?User $user = null;

    /** Decides which format rules apply and what a user interface may do with the alias. */
    #[ORM\Column(length: 20, enumType: EmailAliasOrigin::class)]
    private EmailAliasOrigin $origin = EmailAliasOrigin::Manual;

    /**
     * A disabled address is no longer used for writing, but keeps being resolved on reception: a
     * mail arriving on an old address must reach the right student, not the "to be linked" queue.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocalPart(): string
    {
        return $this->localPart;
    }

    public function setLocalPart(string $localPart): static
    {
        $this->localPart = $localPart;

        return $this;
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

    public function getOrigin(): EmailAliasOrigin
    {
        return $this->origin;
    }

    public function setOrigin(EmailAliasOrigin $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    /**
     * The format rules, carried by the entity rather than by the screen that will fill it in: part 2
     * does not exist yet, and when it does, it will not be the only creation path.
     */
    #[Assert\Callback]
    public function validateLocalPart(ExecutionContextInterface $context): void
    {
        if (SchoolMailLocalPart::isReserved($this->localPart)) {
            $context->buildViolation('emailAliasReservedLocalPart')
                ->atPath('localPart')
                ->addViolation();

            return;
        }

        if (!SchoolMailLocalPart::isWellFormed($this->localPart)) {
            $context->buildViolation('emailAliasMalformedLocalPart')
                ->atPath('localPart')
                ->addViolation();

            return;
        }

        if ($this->origin->requiresDot() && !SchoolMailLocalPart::hasRequiredDot($this->localPart)) {
            $context->buildViolation('emailAliasMissingDot')
                ->atPath('localPart')
                ->addViolation();
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** The full address, the domain coming from the environment's configuration. */
    public function toAddress(string $domain): string
    {
        return $this->localPart.'@'.$domain;
    }
}
