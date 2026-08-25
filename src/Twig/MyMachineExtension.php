<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\GuestAccountRepository;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Powers the « Mes machines virtuelles » entry, which sits in two different menus - Ressources for
// a student, the profile menu for a teacher - and must not be offered to somebody who has no
// machine. A Twig function rather than a value passed from every controller, same reasoning as
// MessagingExtension's nav badge: the navigation is rendered on every authenticated page and no
// controller owns it.
//
// The answer is held for the request. The two menus are rendered from the same layout and the
// question is asked once per menu, so without this a page would count twice for one answer that
// cannot have changed in between. It is dropped between requests: under FrankenPHP worker mode a
// Twig extension is a singleton that outlives the request, and a memo with no reset would serve the
// first visitor's answer to every later one in the same worker - here, somebody else's menu entry.
class MyMachineExtension extends AbstractExtension implements ResetInterface
{
    private ?bool $hasMachines = null;

    public function __construct(private readonly GuestAccountRepository $accounts)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('has_virtual_machines', $this->hasVirtualMachines(...)),
        ];
    }

    public function hasVirtualMachines(?User $user): bool
    {
        if (null === $user) {
            return false;
        }

        return $this->hasMachines ??= $this->accounts->userHasAny($user);
    }

    #[\Override]
    public function reset(): void
    {
        $this->hasMachines = null;
    }
}
