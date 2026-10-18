<?php

declare(strict_types=1);

namespace App\Service\SeriousGame;

/**
 * « Qui joue, qui collectionne, qui lit » in the serious games (design/validated/serious-games.md
 * §3.2), as pure functions on primitives. App\Security\Voter\SeriousGameVoter gathers the facts and
 * asks here; nothing else decides.
 *
 * - **Play**: the module (`Feature::SeriousGames`) lit for the person, the game published, and the
 *   game lit for one of their roles. The administrator plays everything, unpublished included.
 *   Tutors and external accounts get nothing, whatever the matrix says (§1.9).
 * - **Collect**: students only. An adult plays, keeps their progress, and collects nothing (§1.10).
 * - **Read a student**: the student themself, the administrator, and a teacher of one of the
 *   student's **active** classes - asked now, not at the time of the game, which is what gives the
 *   teachers of a new class the whole history (§1.9). Staff and staff-lead read no student by
 *   name: theirs are the aggregates.
 */
final class SeriousGameRules
{
    private const string ADMIN = 'ROLE_ADMIN';

    /** The roles that read and play nothing in the serious games. */
    private const array SHUT_OUT = ['ROLE_TUTOR', 'ROLE_EXTERNAL'];

    /** The adult roles: a student holding one of them plays as the adult. */
    private const array ADULTS = ['ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_ADMIN'];

    /**
     * @param list<string>        $roles          the player's roles
     * @param array<string, bool> $gameRoleSwitch role => lit, the game's line of the matrix
     */
    public static function canPlay(array $roles, bool $moduleLit, bool $gamePublished, array $gameRoleSwitch): bool
    {
        if (self::isAdmin($roles)) {
            return true;
        }

        if (self::isShutOut($roles) || !$moduleLit || !$gamePublished) {
            return false;
        }

        foreach ($roles as $role) {
            if (true === ($gameRoleSwitch[$role] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $roles */
    public static function canCollect(array $roles): bool
    {
        return \in_array('ROLE_STUDENT', $roles, true) && [] === array_intersect(self::ADULTS, $roles);
    }

    /**
     * @param list<string> $viewerRoles
     * @param bool         $teachesAnActiveClassOfTheStudent whether the viewer is, today, a teacher of
     *                                                       one of the student's active classes
     */
    public static function canReadStudent(array $viewerRoles, int $viewerId, int $studentId, bool $teachesAnActiveClassOfTheStudent): bool
    {
        if (self::isAdmin($viewerRoles)) {
            return true;
        }

        if (self::isShutOut($viewerRoles)) {
            return false;
        }

        if ($viewerId === $studentId) {
            return true;
        }

        return \in_array('ROLE_TEACHER', $viewerRoles, true) && $teachesAnActiveClassOfTheStudent;
    }

    /** @param list<string> $viewerRoles */
    public static function canReadClass(array $viewerRoles, bool $teachesTheClass): bool
    {
        if (self::isAdmin($viewerRoles)) {
            return true;
        }

        return !self::isShutOut($viewerRoles) && \in_array('ROLE_TEACHER', $viewerRoles, true) && $teachesTheClass;
    }

    /** @param list<string> $roles */
    public static function canReadAggregate(array $roles): bool
    {
        return [] !== array_intersect(['ROLE_STAFF', 'ROLE_STAFF-LEAD', self::ADMIN], $roles);
    }

    /** @param list<string> $roles */
    public static function canManage(array $roles): bool
    {
        return self::isAdmin($roles);
    }

    /** @param list<string> $roles */
    private static function isAdmin(array $roles): bool
    {
        return \in_array(self::ADMIN, $roles, true);
    }

    /** @param list<string> $roles */
    private static function isShutOut(array $roles): bool
    {
        return [] !== array_intersect(self::SHUT_OUT, $roles);
    }
}
