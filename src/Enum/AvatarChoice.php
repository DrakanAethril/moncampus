<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What stands for a person wherever Formatool draws a face (design/validated/quiz-tournament.md §16):
 * the photo they sent, the avatar they drew, or one of the ménagerie's ready-made avatars
 * (App\Service\FixedAvatars). `User::$avatarChoice` holds it; null is the initials. Choosing never
 * deletes the others: the photo, the drawing and the ménagerie avatar all stay, ready to be chosen
 * again (App\Service\UserAvatars).
 */
enum AvatarChoice: string
{
    case Photo = 'photo';
    case Drawn = 'drawn';
    case Fixed = 'fixed';

    /** The value « Mon avatar » posts for the initials. */
    public const string NONE = 'none';

    /** A choice is offered only when there is something behind it. */
    public function isPossible(bool $hasPhoto, bool $hasDrawing, bool $hasFixed = false): bool
    {
        return match ($this) {
            self::Photo => $hasPhoto,
            self::Drawn => $hasDrawing,
            self::Fixed => $hasFixed,
        };
    }

    /**
     * The choice a submitted value names: `photo`, `drawn`, `fixed`, or `none` for the initials.
     *
     * @throws \ValueError on any other value
     */
    public static function fromFormValue(string $value): ?self
    {
        return self::NONE === $value ? null : self::from($value);
    }
}
