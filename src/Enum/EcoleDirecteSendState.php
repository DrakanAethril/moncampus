<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What sending one part to École Directe would do, read before anything is sent: the preview the
 * teacher confirms is built from these, and only New and Replace ever leave.
 */
enum EcoleDirecteSendState: string implements HasBadge
{
    /** École Directe has nothing there yet. */
    case New = 'new';

    /** École Directe already says something else there, and sending replaces it. */
    case Replace = 'replace';

    /** École Directe already says exactly this. Nothing to send. */
    case Same = 'same';

    /**
     * École Directe says something is written there but did not hand the text back. Not sent:
     * sending a slot rebuilt without what it already carries could lose its attachments, and the
     * teacher is shown the line so they can look for themselves.
     */
    case Unreadable = 'unreadable';

    public function labelKey(): string
    {
        return match ($this) {
            self::New => 'ecoleDirecteSendNewLabel',
            self::Replace => 'ecoleDirecteSendReplaceLabel',
            self::Same => 'ecoleDirecteSendSameLabel',
            self::Unreadable => 'ecoleDirecteSendUnreadableLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::New => BadgeTone::Green,
            self::Replace => BadgeTone::Gold,
            self::Same => BadgeTone::Gray,
            self::Unreadable => BadgeTone::Red,
        };
    }

    public function sends(): bool
    {
        return self::New === $this || self::Replace === $this;
    }

    /**
     * Compared as École Directe will show it: surrounding blanks do not make two texts differ.
     *
     * @param string|null $current null when École Directe flags the part as written without
     *                             handing its text back
     */
    public static function between(?string $current, string $wanted): self
    {
        return match (true) {
            null === $current => self::Unreadable,
            '' === trim($current) => self::New,
            trim($current) === trim($wanted) => self::Same,
            default => self::Replace,
        };
    }
}
