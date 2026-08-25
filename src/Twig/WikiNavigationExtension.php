<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\WikiRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Powers the « Wikis partagés » entry of the Ressources menu, which is only offered to somebody who
 * actually has one. Same posture as « Mes machines virtuelles »: an entry that always opens on
 * « aucun wiki » is an entry people learn to ignore, and the screen behind it is not a place one
 * goes to create something - a shared wiki is made from a class or by a colleague adding you.
 *
 * A Twig function rather than a value passed from every controller: the navigation is rendered on
 * every authenticated page and no controller owns it.
 *
 * The answer is held for the request and dropped between requests. Under FrankenPHP worker mode a
 * Twig extension is a singleton that outlives the request, so a memo with no reset would serve the
 * first visitor's answer to every later one in the same worker - which for this question means
 * showing somebody else's menu entry.
 */
class WikiNavigationExtension extends AbstractExtension implements ResetInterface
{
    private ?bool $hasSharedWikis = null;

    public function __construct(
        private readonly WikiRepository $wikis,
        private readonly ProgramRepository $programs,
        private readonly Security $security,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('has_shared_wikis', $this->hasSharedWikis(...)),
        ];
    }

    public function hasSharedWikis(): bool
    {
        if (null !== $this->hasSharedWikis) {
            return $this->hasSharedWikis;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return $this->hasSharedWikis = false;
        }

        return $this->hasSharedWikis = $this->wikis->hasSharedFor($user, $this->programs->findAllWithMember($user));
    }

    #[\Override]
    public function reset(): void
    {
        $this->hasSharedWikis = null;
    }
}
