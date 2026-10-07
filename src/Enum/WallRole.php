<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What somebody is on one collaborative wall. Never stored: App\Service\Wall\WallAccess reads it
 * off the wall's owner, its named members and its classes each time it is asked.
 *
 * - `Owner` created the wall: everything, sharing and deletion included.
 * - `Manager` runs it alongside the owner - a colleague a teacher shared their wall with: the
 *   settings, the lists, the moderation, the copies. Never the sharing, never the deletion.
 * - `Participant` writes on it within what the wall's settings allow.
 */
enum WallRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Participant = 'participant';

    public function manages(): bool
    {
        return self::Participant !== $this;
    }
}
