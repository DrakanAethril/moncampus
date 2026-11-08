<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\FixedAvatars;
use App\Service\UserAvatars;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The face of a person in a template, by the choice they made on « Mon profil »
 * (App\Service\UserAvatars, design/validated/quiz-tournament.md §16): the main bar asks on every
 * page, hence a Twig function rather than a variable passed by every controller.
 *
 * `user_avatar(user)` answers both halves at once (`{photo, drawn}`); `avatar_url` and
 * `drawn_avatar` each answer one - null when the other, or the initials, stand for the person.
 * `fixed_avatar_url(id)` is a ménagerie avatar's picture (App\Service\FixedAvatars), `fixed_avatars()`
 * the whole ménagerie - the guest page of a live contest draws its twenty from it.
 */
class AvatarExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserAvatars $userAvatars,
        private readonly FixedAvatars $fixedAvatars,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_avatar', $this->userAvatars->of(...)),
            new TwigFunction('avatar_url', $this->userAvatars->photoUrl(...)),
            new TwigFunction('drawn_avatar', $this->userAvatars->drawnConfig(...)),
            new TwigFunction('fixed_avatar_url', $this->fixedAvatars->url(...)),
            new TwigFunction('fixed_avatars', $this->fixedAvatars->all(...)),
        ];
    }
}
