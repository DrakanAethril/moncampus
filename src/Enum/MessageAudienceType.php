<?php

namespace App\Enum;

/**
 * Who an App\Entity\AudienceTargetable (MessageThread, Announcement, AgendaEvent) was addressed
 * to - see App\Service\AudienceResolver and, for MessageThread specifically,
 * design/validated/internal-messaging.md. No separate "single person" case: that's just Manual
 * with one recipient - see MessageThread's docblock for how thread shape (1:1 vs announcement) is
 * derived from the actual resolved recipient count, not from this type.
 *
 * "Program" replaced the older separate ProgramStudents/ProgramTeachers cases: a single audience
 * can now target several Programs at once (AudienceTargetable::getPrograms(), a collection
 * instead of one Program) and independently include students and/or teachers of each
 * (AudienceTargetable::isIncludeStudents()/isIncludeTeachers()) - so "students and teachers of
 * Program A and B" is one audience selection, not two separate ones.
 */
enum MessageAudienceType: string
{
    case Program = 'program';
    case SchoolWide = 'school_wide';
    case Manual = 'manual';

    public function labelKey(): string
    {
        return match ($this) {
            self::Program => 'messageAudienceTypeProgramLabel',
            self::SchoolWide => 'messageAudienceTypeSchoolWideLabel',
            self::Manual => 'messageAudienceTypeManualLabel',
        };
    }
}
