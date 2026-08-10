<?php

declare(strict_types=1);

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
 * included every role at once). Each of the three is single-role, but they are NOT mutually
 * exclusive: a target names a *set* of these (AudienceTargetable::getAudienceTypes()), so "tous
 * les enseignants et tous les personnels" is one audience selection - see that interface's
 * docblock. Outside accounts (ROLE_TUTOR/ROLE_EXTERNAL, see UserRepository::
 * NON_ADDRESSABLE_ROLES) are never reachable through any of them, same as they never were through
 * SchoolWide.
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

    /**
     * Declaration order, which every stored set is sorted into (see AudienceTargetable). Two
     * things ride on that canonical order and would break without it: a resolved audience lists
     * the manual picks last (Manual is declared last), and the "is this set exactly [Manual]"
     * question the recipient syncer asks in SQL compares against one fixed string rather than
     * every permutation - see MessageThreadRepository::findDynamicAudienceThreadsMissingRecipientFor().
     *
     * @param list<self> $types
     *
     * @return list<self>
     */
    public static function sort(array $types): array
    {
        $order = array_flip(array_map(static fn (self $case): string => $case->value, self::cases()));

        // usort reindexes, so what comes back is already a list.
        usort($types, static fn (self $a, self $b): int => $order[$a->value] <=> $order[$b->value]);

        return $types;
    }
}
