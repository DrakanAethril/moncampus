<?php

namespace App\Enum;

/**
 * Whether the files of an AudioRecording are heard by the whole audience or belong to one student
 * each (design_handoff_enregistrements_audio, screen 2 "Fichiers audio").
 *
 * Individual does not rule out common files: an individualised recording may still carry shared
 * instructions everyone hears, on top of each student's own file. Common, on the other hand, has no
 * per-student side at all.
 */
enum AudioRecordingMode: string
{
    case Common = 'common';
    case Individual = 'individual';

    public function labelKey(): string
    {
        return match ($this) {
            self::Common => 'audioRecordingModeCommonLabel',
            self::Individual => 'audioRecordingModeIndividualLabel',
        };
    }

    // Sub-label of the two radio cards of step 1.
    public function hintKey(): string
    {
        return match ($this) {
            self::Common => 'audioRecordingModeCommonHint',
            self::Individual => 'audioRecordingModeIndividualHint',
        };
    }
}
