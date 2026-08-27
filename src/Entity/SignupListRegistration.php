<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SignupListRegistrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One user's registration on a SignupList - the roster. Unlike MessageThreadRecipient (its closest
 * structural analog), there's no soft-delete state to preserve: unregistering is a real state
 * change the roster should reflect immediately, not a "hide from my own view" toggle, so
 * SignupListController::unregister() hard-deletes the row.
 */
#[ORM\Entity(repositoryClass: SignupListRegistrationRepository::class)]
#[ORM\Table(name: 'signup_list_registration')]
#[ORM\UniqueConstraint(name: 'signup_list_registration_unique', columns: ['signup_list_id', 'user_id'])]
class SignupListRegistration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SignupList::class)]
    #[ORM\JoinColumn(name: 'signup_list_id', nullable: false)]
    #[Assert\NotNull]
    private ?SignupList $signupList = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    #[Assert\NotNull]
    private ?User $user = null;

    #[ORM\Column(name: 'registered_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $registeredAt;

    /**
     * Whether the person actually turned up - ticked by whoever manages the list, after the fact.
     *
     * Added for the campus game, which pays « l'inscription **tenue** » and never the inscription
     * itself (design/validated/gamification.md §4, decision 4): signing up leaves a row and nothing
     * else, and a rule that paid it would pay a click. It is useful outside the game too - a list
     * whose roster nobody ever confirms is a list nobody can report on.
     *
     * Deliberately a single boolean with a date, and no absence of any kind: an unticked line means
     * « nobody said », never « was not there ».
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $attended = false;

    #[ORM\Column(name: 'attended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $attendedAt = null;

    public function __construct(SignupList $signupList, User $user)
    {
        $this->signupList = $signupList;
        $this->user = $user;
        $this->registeredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSignupList(): ?SignupList
    {
        return $this->signupList;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getRegisteredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function hasAttended(): bool
    {
        return $this->attended;
    }

    public function getAttendedAt(): ?\DateTimeImmutable
    {
        return $this->attendedAt;
    }

    public function setAttended(bool $attended): static
    {
        $this->attended = $attended;
        $this->attendedAt = $attended ? ($this->attendedAt ?? new \DateTimeImmutable()) : null;

        return $this;
    }
}
