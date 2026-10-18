<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\SeriousGame;
use App\Entity\User;
use App\SeriousGame\SeriousGameManifest;
use App\Service\SeriousGame\SeriousGameAccess;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Powers the « Serious games » entry of Ressources (design/validated/serious-games.md §3.3): an
 * adult is offered it only when a game is actually playable for them. Also hands the screens a
 * game's own vocabulary (serious_game_words()). The answer is held for the
 * request and dropped between requests - the FrankenPHP worker keeps this extension alive.
 */
class SeriousGameExtension extends AbstractExtension implements ResetInterface
{
    private ?bool $playable = null;

    public function __construct(
        private readonly SeriousGameAccess $access,
        private readonly Security $security,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('serious_games_playable', $this->playable(...)),
            new TwigFunction('serious_game_words', $this->words(...)),
        ];
    }

    public function playable(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->playable ??= $this->access->hasPlayableGame($user);
    }

    /**
     * A game's own vocabulary - « tablettes », « pièces », « lézardes » - for the screens that
     * speak of it (SeriousGameManifest::words()).
     *
     * @return array<string, mixed>
     */
    public function words(SeriousGame $game): array
    {
        return (new SeriousGameManifest($game->getManifest()))->words();
    }

    #[\Override]
    public function reset(): void
    {
        $this->playable = null;
    }
}
