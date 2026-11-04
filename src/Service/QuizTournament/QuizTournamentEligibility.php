<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * Who takes part in a quiz tournament, who reads it, who runs it and who opens a round in it
 * (design/validated/quiz-tournament.md §2), as pure functions on primitives.
 * App\Security\Voter\QuizTournamentVoter gathers the facts (App\Service\QuizTournament\QuizTournamentAccess)
 * and asks here; nothing else decides.
 *
 * - **Eligible**: a student (`ROLE_STUDENT`) the tournament's targeting reaches
 *   (App\Service\StructureTargeting\StructureTargetRule - active formation, test zone). Read at
 *   every challenge, acceptance and start, never frozen.
 * - **In progress**: opened by an administrator, and between its start (included) and its end
 *   (excluded).
 * - **Play**: eligible, the feature lit, the tournament in progress.
 * - **Manage** (create, edit, open): the administrator alone (§1, decision 18).
 * - **View**: the administrator always. A tournament not yet opened is nobody else's to see - not
 *   the students' (§3), and not the teachers', who could only find a round they cannot run in it.
 *   Opened: its creator, the staff, an eligible student, a teacher of a targeted formation.
 * - **Open a round**: a teacher of *that* targeted formation, while the tournament runs. Not
 *   staff-bypassed: a round is a class, and it needs the teacher who has that class in front of them.
 */
final class QuizTournamentEligibility
{
    private const string ADMIN = 'ROLE_ADMIN';
    private const string STUDENT = 'ROLE_STUDENT';
    private const string TEACHER = 'ROLE_TEACHER';
    private const array STAFF = ['ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    /** @param list<string> $roles */
    public static function isEligible(array $roles, bool $reachedByTargeting): bool
    {
        return \in_array(self::STUDENT, $roles, true) && $reachedByTargeting;
    }

    public static function isInProgress(bool $opened, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, \DateTimeImmutable $now): bool
    {
        return $opened && $now >= $startsAt && $now < $endsAt;
    }

    /** @param list<string> $roles */
    public static function canPlay(array $roles, bool $featureLit, bool $reachedByTargeting, bool $inProgress): bool
    {
        return $featureLit && $inProgress && self::isEligible($roles, $reachedByTargeting);
    }

    /** @param list<string> $roles */
    public static function canManage(array $roles): bool
    {
        return \in_array(self::ADMIN, $roles, true);
    }

    /** @param list<string> $roles */
    public static function canView(array $roles, bool $featureLit, bool $opened, bool $isCreator, bool $reachedByTargeting, bool $teachesATargetedProgram): bool
    {
        if (self::canManage($roles)) {
            return true;
        }

        if (!$opened || !$featureLit) {
            return false;
        }

        return $isCreator
            || [] !== array_intersect(self::STAFF, $roles)
            || self::isEligible($roles, $reachedByTargeting)
            || (\in_array(self::TEACHER, $roles, true) && $teachesATargetedProgram);
    }

    /** @param list<string> $roles */
    public static function canOpenRound(array $roles, bool $featureLit, bool $inProgress, bool $teachesThatTargetedProgram): bool
    {
        return $featureLit && $inProgress && \in_array(self::TEACHER, $roles, true) && $teachesThatTargetedProgram;
    }
}
