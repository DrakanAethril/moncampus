<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\UserAvatars;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Powers the navbar avatar rendered in templates/layout/app.html.twig (shared across every
// authenticated page), plus the profile page - a Twig function rather than passing this from
// every controller, same reasoning as StructureNavigationExtension.
class AvatarExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserAvatars $userAvatars,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('avatar_url', $this->getAvatarUrl(...)),
            // A student's serious-games avatar, standing in for the photo they did not upload
            // (App\Service\UserAvatars) - null when the photo or the initials answer.
            new TwigFunction('drawn_avatar', $this->userAvatars->drawnConfig(...)),
        ];
    }

    public function getAvatarUrl(?User $user): ?string
    {
        return $this->userAvatars->photoUrl($user);
    }
}
