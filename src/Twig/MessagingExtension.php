<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\MessageThreadRecipientRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Powers the "Messages" nav badge in templates/layout/app.html.twig - a Twig function rather than
// passing this from every controller, same reasoning as AvatarExtension/StructureNavigationExtension.
class MessagingExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageThreadRecipientRepository $recipientRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_message_thread_count', $this->unreadMessageThreadCount(...)),
        ];
    }

    public function unreadMessageThreadCount(?User $user): int
    {
        return null !== $user ? $this->recipientRepository->countUnreadForUser($user) : 0;
    }
}
