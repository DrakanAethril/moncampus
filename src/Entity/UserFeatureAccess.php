<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Feature;
use App\Enum\FeatureAccessState;
use App\Repository\UserFeatureAccessRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person's derogation on one feature - « une fonctionnalité s'éteint pour un rôle, et se
 * rallume pour une personne » (design/validated/feature-access.md §5).
 *
 * **`default` is never stored.** The card offers three buttons, but only two of them make a row:
 * choosing « Par défaut » deletes it. That is what leaves this table empty at deployment, with the
 * matrix deciding alone, and it is what keeps « par défaut » meaning "whatever the matrix says
 * today" rather than "whatever it said the day somebody clicked".
 *
 * It is read one step before the formation flag, which is the whole point of §3.5: a student
 * looking for a company in a formation whose Courrier école is closed gets a mailbox, alone.
 */
#[ORM\Entity(repositoryClass: UserFeatureAccessRepository::class)]
#[ORM\Table(name: 'user_feature_access')]
#[ORM\UniqueConstraint(name: 'uniq_user_feature', columns: ['user_id', 'feature'])]
class UserFeatureAccess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, enumType: Feature::class)]
    private Feature $feature;

    #[ORM\Column(length: 16, enumType: FeatureAccessState::class)]
    private FeatureAccessState $state;

    public function __construct(User $user, Feature $feature, FeatureAccessState $state)
    {
        $this->user = $user;
        $this->feature = $feature;
        $this->state = $state;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFeature(): Feature
    {
        return $this->feature;
    }

    public function getState(): FeatureAccessState
    {
        return $this->state;
    }

    public function setState(FeatureAccessState $state): static
    {
        $this->state = $state;

        return $this;
    }
}
