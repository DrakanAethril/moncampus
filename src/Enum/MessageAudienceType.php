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
 *
 * AllStudents/AllTeachers/AllStaff replaced the older single SchoolWide case ("Tout
 * l'établissement"), which was dropped for being confusing (unclear scope, and it silently
 * included every role at once). Each of the three is single-role and mutually exclusive - there's
 * no "all students and teachers" shortcut here the way Program can combine roles, since these
 * aren't scoped to a Program in the first place. ROLE_EXTERNAL is never reachable through any of
 * them, same as it never was through SchoolWide.
 */
enum MessageAudienceType: string
{
    case Program = 'program';
    case AllStudents = 'all_students';
    case AllTeachers = 'all_teachers';
    case AllStaff = 'all_staff';
    case Manual = 'manual';

    public function labelKey(): string
    {
        return match ($this) {
            self::Program => 'messageAudienceTypeProgramLabel',
            self::AllStudents => 'messageAudienceTypeAllStudentsLabel',
            self::AllTeachers => 'messageAudienceTypeAllTeachersLabel',
            self::AllStaff => 'messageAudienceTypeAllStaffLabel',
            self::Manual => 'messageAudienceTypeManualLabel',
        };
    }
}
