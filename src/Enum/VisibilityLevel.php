<?php

namespace App\Enum;

/**
 * Who can see a given nav entry / be offered a Program as an audience-picker choice. Modeled as a
 * strict ordered hierarchy, not independent exclusive tiers: Everyone(0) < TeachersOnly(1) <
 * StaffAdmin(2) < AdminOnly(3) < Hidden(any). A viewer's highest role maps to a level, and a tier
 * is visible iff the viewer's level is at least the tier's level - so a Staff/Admin always also
 * sees a "TeachersOnly" entry, same as they already see everything gated at a lower tier. Hidden
 * is visible to no one, including Admin, in the normal nav/pickers - Admin still manages Programs/
 * Modalities directly through the unaffected Settings > Structure screens regardless of this
 * field, so no special bypass is needed here.
 */
enum VisibilityLevel: string
{
    case Everyone = 'everyone';
    case TeachersOnly = 'teachers_only';
    case StaffAdmin = 'staff_admin';
    case AdminOnly = 'admin_only';
    case Hidden = 'hidden';

    public function labelKey(): string
    {
        return match ($this) {
            self::Everyone => 'visibilityLevelEveryoneLabel',
            self::TeachersOnly => 'visibilityLevelTeachersOnlyLabel',
            self::StaffAdmin => 'visibilityLevelStaffAdminLabel',
            self::AdminOnly => 'visibilityLevelAdminOnlyLabel',
            self::Hidden => 'visibilityLevelHiddenLabel',
        };
    }

    private function level(): int
    {
        return match ($this) {
            self::Everyone => 0,
            self::TeachersOnly => 1,
            self::StaffAdmin => 2,
            self::AdminOnly => 3,
            self::Hidden => \PHP_INT_MAX,
        };
    }

    /** @param list<string> $roles */
    public function allowsRoles(array $roles): bool
    {
        $viewerLevel = match (true) {
            \in_array('ROLE_ADMIN', $roles, true) => 3,
            \in_array('ROLE_STAFF', $roles, true), \in_array('ROLE_STAFF-LEAD', $roles, true) => 2,
            \in_array('ROLE_TEACHER', $roles, true) => 1,
            default => 0,
        };

        return $viewerLevel >= $this->level();
    }
}
