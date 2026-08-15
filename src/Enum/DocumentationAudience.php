<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Who an App\Entity\DocumentationArticle is written for - the "Visibilité" pastilles of the
 * handoff (2c/2d), one per kind of person rather than one per LDAP role: staff and staff-lead
 * read the same documentation as any other member of the personnel, so they share one value.
 *
 * This is only half of the reading rule. The other half is the article's perimeter, which says
 * *which* students or teachers - the two are ANDed by App\Service\DocumentationAccess.
 *
 * Admin is not an audience: an admin reads everything, like staff and staff-lead, whatever the
 * pastilles say. Same shape as App\Enum\HelpAudience, deliberately its own enum: the two features
 * name different populations (this one has no "personnels" in Help) and must be free to diverge.
 */
enum DocumentationAudience: string
{
    case Student = 'student';
    case Teacher = 'teacher';
    case Staff = 'staff';
    case Tutor = 'tutor';

    /**
     * The pastilles in the order the handoff draws them, Tuteurs last because it is the only one
     * off by default.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Student, self::Teacher, self::Staff, self::Tutor];
    }

    /** @return list<self> */
    public static function defaults(): array
    {
        return [self::Student, self::Teacher, self::Staff];
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Student => 'documentationAudienceStudentLabel',
            self::Teacher => 'documentationAudienceTeacherLabel',
            self::Staff => 'documentationAudienceStaffLabel',
            self::Tutor => 'documentationAudienceTutorLabel',
        };
    }

    /**
     * The audiences a set of roles belongs to, in display order.
     *
     * @param list<string> $roles
     *
     * @return list<self>
     */
    public static function fromRoles(array $roles): array
    {
        $audiences = [];

        if (\in_array('ROLE_STUDENT', $roles, true)) {
            $audiences[] = self::Student;
        }
        if (\in_array('ROLE_TEACHER', $roles, true)) {
            $audiences[] = self::Teacher;
        }
        // ROLE_SUPPORT-TECH sits here rather than in a fourth value: for the documentation base,
        // the technician is a member of the personnel like any other.
        if (\in_array('ROLE_STAFF', $roles, true)
            || \in_array('ROLE_STAFF-LEAD', $roles, true)
            || \in_array('ROLE_SUPPORT-TECH', $roles, true)) {
            $audiences[] = self::Staff;
        }
        if (\in_array('ROLE_TUTOR', $roles, true)) {
            $audiences[] = self::Tutor;
        }

        return $audiences;
    }
}
