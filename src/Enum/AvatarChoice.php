<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What stands for a person wherever Formatool draws a face (design/validated/quiz-tournament.md §16):
 * the photo they sent, or the avatar they drew. `User::$avatarChoice` holds it; null is the
 * initials. Choosing never deletes the other: the photo and the drawing both stay, ready to be
 * chosen again (App\Service\UserAvatars).
 */
enum AvatarChoice: string
{
    case Photo = 'photo';
    case Drawn = 'drawn';

    /** The value « Mon avatar » posts for the initials. */
    public const string NONE = 'none';

    /** A choice is offered only when there is something behind it. */
    public function isPossible(bool $hasPhoto, bool $hasDrawing): bool
    {
        return match ($this) {
            self::Photo => $hasPhoto,
            self::Drawn => $hasDrawing,
        };
    }

    /**
     * The choice a submitted value names: `photo`, `drawn`, or `none` for the initials.
     *
     * @throws \ValueError on any other value
     */
    public static function fromFormValue(string $value): ?self
    {
        return self::NONE === $value ? null : self::from($value);
    }
}
