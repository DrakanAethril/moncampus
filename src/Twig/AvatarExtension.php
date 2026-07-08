<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\FileUploadService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Powers the navbar avatar rendered in templates/layout/app.html.twig (shared across every
// authenticated page), plus the profile page - a Twig function rather than passing this from
// every controller, same reasoning as StructureNavigationExtension.
class AvatarExtension extends AbstractExtension
{
    public function __construct(
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('avatar_url', $this->getAvatarUrl(...)),
        ];
    }

    public function getAvatarUrl(?User $user): ?string
    {
        if (null === $user || null === $user->getAvatarKey()) {
            return null;
        }

        return $this->fileUploadService->url($user->getAvatarKey());
    }
}
