<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\User;
use App\Repository\GameProfileRepository;
use App\Twig\AvatarExtension;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The decorated avatar of the person looking at the page, or null.
 *
 * Null is by far the common answer and it has to be cheap: the main bar asks on every authenticated
 * page, and until an establishment switches the game on nobody has a badge. The memo is per request
 * and keyed by account, so a page drawing the bar twice pays once.
 *
 * A teacher has no badge either, deliberately - the game is played by students, and a ring around a
 * teacher's avatar would announce a level nobody earns.
 *
 * **`ResetInterface`, and it is load-bearing rather than tidy.** FrankenPHP serves this application
 * in worker mode: the container outlives the request, so a memo kept here would answer the *next*
 * request with what the last one saw - a stale one, and one holding entities of an EntityManager
 * that has since been cleared. Symfony calls reset() between requests through `services_resetter`.
 */
final class GameBadgeProvider implements ResetInterface
{
    /** @var array<int, GameBadge|null> */
    private array $cache = [];

    public function __construct(
        private readonly GameAccess $access,
        private readonly GameProfileRepository $profiles,
        private readonly GameLevelResolver $levels,
        private readonly GameLevelBoard $board,
        private readonly GameTrackResolver $tracks,
        private readonly AvatarExtension $avatars,
    ) {
    }

    public function forUser(?User $user): ?GameBadge
    {
        if (null === $user || null === $user->getId()) {
            return null;
        }

        return $this->cache[$user->getId()] ??= $this->resolve($user);
    }

    private function resolve(User $user): ?GameBadge
    {
        $program = $this->access->primaryProgramFor($user);

        if (null === $program) {
            return null;
        }

        $profile = $this->profiles->findForStudent($user);
        $progress = $this->levels->resolve($profile?->getTotalPoints() ?? 0);

        // A chosen title survives a level change; without one, the level's own wording answers.
        // The filière is the student's own, read off their option: two students of one SIO class
        // read « Chasseur·se de bugs » and « Chasseur·se de pannes ».
        $title = $profile?->getDisplayedTitle() ?? $this->board->titleFor($this->tracks->forStudent($user, $program), $progress->level->level);

        return new GameBadge(
            $user,
            $program,
            $progress,
            $title,
            $this->initialsOf($user),
            $this->avatars->getAvatarUrl($user),
        );
    }

    private function initialsOf(User $user): string
    {
        $initials = $user->getInitials();

        if (null !== $initials && '' !== $initials) {
            return $initials;
        }

        $name = $user->getDisplayName() ?? $user->getUsername();

        return mb_strtoupper(mb_substr($name, 0, 1));
    }

    #[\Override]
    public function reset(): void
    {
        $this->cache = [];
    }
}
