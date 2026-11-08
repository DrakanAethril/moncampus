<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\AvatarChoice;
use App\Repository\SeriousGameAvatarRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What stands for a person where Formatool draws a face: what they chose on « Mon profil »
 * (`User::$avatarChoice`, design/validated/quiz-tournament.md §16), for every role alike - the photo
 * they sent, the avatar they drew, an avatar of the ménagerie, or nothing, in which case the
 * templates write the initials. A choice with nothing behind it (a photo chosen, then never there)
 * also answers the initials. Choosing deletes nothing: the photo, the drawing and the ménagerie
 * avatar stay, only what is shown changes.
 *
 * A ménagerie avatar is a picture with an address (App\Service\FixedAvatars), so it is handed out
 * in the `photo` half: every screen that draws a photo draws it unchanged.
 *
 * The drawn avatar is handed out as its stored settings: the drawing is the one JavaScript module
 * the platform and the games share (`public/serious-games/shared/avatar.js`, through the
 * `sg-avatar` controller), which normalises them again before drawing. The serious games
 * themselves always draw the drawing, whatever the choice - they ask SeriousGameAvatars, not this.
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
        private readonly SeriousGameAvatarRepository $drawings,
        private readonly FixedAvatars $fixedAvatars,
    ) {
    }

    /**
     * The rule, on primitives: the choice picks one of the three, and a choice with nothing behind
     * it shows none.
     *
     * @param array<string, mixed>|null $drawn
     *
     * @return UserAvatar
     */
    public static function decide(?AvatarChoice $choice, ?string $photo, ?array $drawn, ?string $fixed = null): array
    {
        return [
            'photo' => match ($choice) {
                AvatarChoice::Photo => $photo,
                AvatarChoice::Fixed => $fixed,
                default => null,
            },
            'drawn' => AvatarChoice::Drawn === $choice ? $drawn : null,
        ];
    }

    /** The picture to show for this person - their photo or their ménagerie avatar, if chosen. */
    public function photoUrl(?User $user): ?string
    {
        return match ($user?->getAvatarChoice()) {
            AvatarChoice::Photo => $this->uploadedPhotoUrl($user),
            AvatarChoice::Fixed => $this->fixedAvatarUrl($user),
            default => null,
        };
    }

    /** The ménagerie avatar this person picked, shown or not - « Mon avatar » offers it back. */
    public function fixedAvatarUrl(?User $user): ?string
    {
        return $this->fixedAvatars->url($user?->getAvatarFixed());
    }

    /** The photo this person sent, shown or not - « Mon avatar » offers it back. */
    public function uploadedPhotoUrl(?User $user): ?string
    {
        $key = $user?->getAvatarKey();

        return null === $key ? null : $this->fileUploadService->url($key);
    }

    /**
     * The drawn avatar to show for this person, or null - unless they chose it and drew one.
     *
     * @return array<string, mixed>|null
     */
    public function drawnConfig(?User $user): ?array
    {
        $id = $user?->getId();
        if (null === $user || null === $id || AvatarChoice::Drawn !== $user->getAvatarChoice()) {
            return null;
        }

        if (!\array_key_exists($id, $this->drawn)) {
            $this->drawn[$id] = $this->drawings->findOneForUser($user)?->getConfig();
        }

        return $this->drawn[$id];
    }

    /** @return UserAvatar */
    public function of(?User $user): array
    {
        return ['photo' => $this->photoUrl($user), 'drawn' => $this->drawnConfig($user)];
    }

    /**
     * The same reading for several people at once, by user id - one query for the drawn avatars,
     * asked only for the people who chose theirs.
     *
     * @param list<User> $users
     *
     * @return array<int, UserAvatar>
     */
    public function forUsers(array $users): array
    {
        $drawers = array_values(array_filter($users, static fn (User $user): bool => AvatarChoice::Drawn === $user->getAvatarChoice()));
        $drawn = [] === $drawers ? [] : $this->drawings->findForUsers($drawers);

        $out = [];
        foreach ($users as $user) {
            $id = (int) $user->getId();
            $out[$id] = self::decide($user->getAvatarChoice(), $this->uploadedPhotoUrl($user), isset($drawn[$id]) ? $drawn[$id]->getConfig() : null, $this->fixedAvatarUrl($user));
        }

        return $out;
    }

    #[\Override]
    public function reset(): void
    {
        $this->drawn = [];
    }
}
