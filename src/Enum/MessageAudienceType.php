<?php

namespace App\Enum;

/**
 * Who a MessageThread was addressed to - see App\Service\MessageAudienceResolver and
 * design/validated/internal-messaging.md. No separate "single person" case: that's just Manual
 * with one recipient - see MessageThread's docblock for how thread shape (1:1 vs announcement) is
 * derived from the actual resolved recipient count, not from this type.
 */
enum MessageAudienceType: string
{
    case ProgramStudents = 'program_students';
    case ProgramTeachers = 'program_teachers';
    case SchoolWide = 'school_wide';
    case Manual = 'manual';

    public function labelKey(): string
    {
        return match ($this) {
            self::ProgramStudents => 'messageAudienceTypeProgramStudentsLabel',
            self::ProgramTeachers => 'messageAudienceTypeProgramTeachersLabel',
            self::SchoolWide => 'messageAudienceTypeSchoolWideLabel',
            self::Manual => 'messageAudienceTypeManualLabel',
        };
    }
}
