<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Who a help section or article is written for.
 *
 * This is deliberately a small list of *audiences*, not a mirror of the LDAP roles: staff and
 * staff-lead read the same documentation, so they share one value, and an admin is not an audience
 * at all - an admin sees everything, which is what App\Service\HelpAccess encodes.
 *
 * Student and Tutor exist so their articles can be written and reviewed before those two roles get
 * an entry point into the help: nothing links them to /help yet.
 */
enum HelpAudience: string
{
    case Teacher = 'teacher';
    case Staff = 'staff';
    case Student = 'student';
    case Tutor = 'tutor';

    public function labelKey(): string
    {
        return match ($this) {
            self::Teacher => 'helpAudienceTeacherLabel',
            self::Staff => 'helpAudienceStaffLabel',
            self::Student => 'helpAudienceStudentLabel',
            self::Tutor => 'helpAudienceTutorLabel',
        };
    }

    /**
     * The audiences a set of roles grants, in display order.
     *
     * @param list<string> $roles
     *
     * @return list<self>
     */
    public static function fromRoles(array $roles): array
    {
        $audiences = [];

        if (in_array('ROLE_TEACHER', $roles, true)) {
            $audiences[] = self::Teacher;
        }
        if (in_array('ROLE_STAFF', $roles, true) || in_array('ROLE_STAFF-LEAD', $roles, true)) {
            $audiences[] = self::Staff;
        }
        if (in_array('ROLE_STUDENT', $roles, true)) {
            $audiences[] = self::Student;
        }
        if (in_array('ROLE_TUTOR', $roles, true)) {
            $audiences[] = self::Tutor;
        }

        return $audiences;
    }
}
