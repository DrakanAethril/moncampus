<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\SeriousGameAvatarRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What stands for a person where Formatool draws a face: the photo uploaded on « Mon profil »
 * first, else - for a student - the avatar drawn in the serious games, else the initials (which
 * the templates write themselves).
 *
 * The drawn avatar is handed out as its stored settings: the drawing is the one JavaScript module
 * the platform and the games share (`public/serious-games/shared/avatar.js`, through the
 * `sg-avatar` controller), which normalises them again before drawing.
 *
 * Memoised per request for the main bar, which asks on every page - hence `ResetInterface`: in
 * worker mode a memo would otherwise answer the next request with what this one saw.
 *
 * @phpstan-type UserAvatar array{photo: string|null, drawn: array<string, mixed>|null}
 */
final class UserAvatars implements ResetInterface
{
    /** @var array<int, array<string, mixed>|null> */
    private array $drawn = [];

    public function __construct(
        private readonly FileUploadService $fileUploadService,
        private readonly SeriousGameAvatarRepository $seriousGameAvatars,
    ) {
    }

    public function photoUrl(?User $user): ?string
    {
        $key = $user?->getAvatarKey();

        return null === $key ? null : $this->fileUploadService->url($key);
    }

    /**
     * The serious-games avatar to draw for this person, or null - when they uploaded a photo, are
     * not a student, or never drew one.
     *
     * @return array<string, mixed>|null
     */
    public function drawnConfig(?User $user): ?array
    {
        if (null === $user || null === $user->getId() || !$this->drawsOne($user)) {
            return null;
        }

        $id = $user->getId();
        if (!\array_key_exists($id, $this->drawn)) {
            $this->drawn[$id] = $this->seriousGameAvatars->findOneForUser($user)?->getConfig();
        }

        return $this->drawn[$id];
    }

    /**
     * The same reading for several people at once, by user id - one query for the drawn avatars.
     *
     * @param list<User> $users
     *
     * @return array<int, UserAvatar>
     */
    public function forUsers(array $users): array
    {
        $drawn = $this->seriousGameAvatars->findForUsers(array_values(array_filter($users, $this->drawsOne(...))));

        $out = [];
        foreach ($users as $user) {
            $id = (int) $user->getId();
            $out[$id] = [
                'photo' => $this->photoUrl($user),
                'drawn' => isset($drawn[$id]) ? $drawn[$id]->getConfig() : null,
            ];
        }

        return $out;
    }

    private function drawsOne(User $user): bool
    {
        return null === $user->getAvatarKey() && \in_array('ROLE_STUDENT', $user->getRoles(), true);
    }

    #[\Override]
    public function reset(): void
    {
        $this->drawn = [];
    }
}
