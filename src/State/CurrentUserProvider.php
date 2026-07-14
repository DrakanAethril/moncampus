<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Backs the GET /api/me operation on User (see the ApiResource attribute on that entity) - "me"
 * has no id in the URL, so instead of the default Doctrine item provider looking up a row by a
 * uriVariable, this just returns whichever User the api firewall's JWT authenticated as.
 */
class CurrentUserProvider implements ProviderInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?User
    {
        /** @var User|null $user */
        $user = $this->security->getUser();

        return $user;
    }
}
