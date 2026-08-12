<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A video's state, as the list's "Statut" column reads it. Nothing is stored: it is read from the
 * files actually uploaded and from the existence of the assignment
 * (App\Entity\VideoResource::getStatus()), so it follows the slightest addition or removal on its
 * own - the same reading AudioRecordingStatus makes of a recording.
 */
enum VideoResourceStatus: string
{
    case Draft = 'draft';
    case Complete = 'complete';
    case WorkCreated = 'work_created';

    public function labelKey(): string
    {
        return match ($this) {
            self::Draft => 'videoResourceStatusDraftLabel',
            self::Complete => 'videoResourceStatusCompleteLabel',
            self::WorkCreated => 'videoResourceStatusWorkCreatedLabel',
        };
    }

    // The audio pills are the same component drawn in the same list, so they are the same classes:
    // a second set of identical rules would only be a second place to forget.
    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'cm-audio-pill--draft',
            self::Complete => 'cm-audio-pill--complete',
            self::WorkCreated => 'cm-audio-pill--work',
        };
    }
}
