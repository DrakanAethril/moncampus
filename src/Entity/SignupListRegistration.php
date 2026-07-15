<?php

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
}
