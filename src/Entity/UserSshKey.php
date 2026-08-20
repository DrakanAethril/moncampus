<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserSshKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A public key one person owns, so that the machines this application creates let them in.
 *
 * Distinct from App\Entity\PlatformSshKey and not a variant of it: that one is the application's
 * own pair, it holds a sealed private half, and it is rotated as a fleet. This one is half a key
 * pair whose other half MonCampus never sees, never holds, and cannot rotate - the person owns it.
 * The only thing the two share is where they end up, `authorized_keys`.
 *
 * The row carries no notion of who may use it. Whose keys are installed is decided at installation
 * time, from the account's own roles, by App\Service\Guest\GuestAuthorizedKeys - so a person who
 * stops being an administrator stops handing out access without anyone having to remember to come
 * and delete rows here.
 *
 * The key is stored without its comment: see App\Service\Guest\SshPublicKey::toStorage(). The label
 * is what names it on the screen, and it stays on the screen.
 */
#[ORM\Entity(repositoryClass: UserSshKeyRepository::class)]
#[ORM\Table(name: 'user_ssh_key')]
class UserSshKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** What the owner calls the machine this key lives on - « MacBook », « poste du bureau ». */
    #[ORM\Column(length: 120)]
    private string $label;

    /** `ssh-ed25519 AAAA…`, type and body, no comment. */
    #[ORM\Column(name: 'public_key', type: Types::TEXT)]
    private string $publicKey;

    /** The `SHA256:…` form, stored so the list can show it without re-reading every key. */
    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct(User $user, string $label, string $publicKey, string $fingerprint)
    {
        $this->user = $user;
        $this->label = $label;
        $this->publicKey = $publicKey;
        $this->fingerprint = $fingerprint;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }
}
