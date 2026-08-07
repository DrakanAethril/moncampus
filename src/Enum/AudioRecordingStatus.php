<?php

namespace App\Enum;

/**
 * A recording's state, as the list's "Statut" column reads it. Nothing is stored: it is read from
 * the files actually recorded and from the existence of the assignment
 * (App\Entity\AudioRecording::getStatus()), so it follows the slightest addition or removal on its
 * own.
 */
enum AudioRecordingStatus: string
{
    case Draft = 'draft';
    case Complete = 'complete';
    case WorkCreated = 'work_created';

    public function labelKey(): string
    {
        return match ($this) {
            self::Draft => 'audioRecordingStatusDraftLabel',
            self::Complete => 'audioRecordingStatusCompleteLabel',
            self::WorkCreated => 'audioRecordingStatusWorkCreatedLabel',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'cm-audio-pill--draft',
            self::Complete => 'cm-audio-pill--complete',
            self::WorkCreated => 'cm-audio-pill--work',
        };
    }
}
