<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Enum\Feature;
use App\Enum\VisibilityLevel;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The single answer to "may this person see this formation's timetable".
 *
 * Three conditions ANDed, and the point is that they are cumulative rather than alternatives:
 * the `timetable` feature is lit for the reader's role, the formation manages its timetable here
 * (Program::$timetableManagementEnabled), **and** the formation's own
 * Program::$timetableVisibility tier admits the reader's roles. Lighting the feature for a role
 * therefore never overrides what a formation decided - deny by default, one screen at a time.
 *
 * It exists because the rule had drifted: the nav entries read the visibility tier, while the
 * screens, the dashboards and the mobile feed read only the feature and the management flag, so
 * a formation set to « Admin only » still served its timetable to its students.
 *
 * App\Security\FeatureAccess memoises its own answer per request, so asking this once per session
 * of a day costs one array lookup after the first call.
 */
class ProgramTimetableAccess
{
    public function __construct(
        private readonly Security $security,
        private readonly FeatureAccess $featureAccess,
    ) {
    }

    public function isVisible(Program $program): bool
    {
        return $this->featureAccess->isEnabled(Feature::Timetable)
            && $program->isTimetableManagementEnabled()
            && $program->getTimetableVisibility()->allowsRoles($this->roles());
    }

    /**
     * @param list<Program> $programs
     *
     * @return list<Program>
     */
    public function filterPrograms(array $programs): array
    {
        return array_values(array_filter($programs, $this->isVisible(...)));
    }

    /**
     * @param list<LessonSession> $sessions
     *
     * @return list<LessonSession>
     */
    public function filterSessions(array $sessions): array
    {
        return array_values(array_filter(
            $sessions,
            fn (LessonSession $session): bool => $this->isVisible($session->getProgram()),
        ));
    }

    /**
     * The tiers to hand a repository query that has to do the same filtering in SQL - the day
     * fallbacks ("today is free, here is the next teaching day") have to skip a hidden formation
     * *before* choosing the day, or they land the reader on a day they cannot see anything on.
     *
     * Only the tier travels: it is the half of the rule that depends on who is reading. The
     * feature is the caller's own gate (#[RequiresFeature]) and the management flag is added by
     * the query itself, which has the Program joined already.
     *
     * @return list<VisibilityLevel>
     */
    public function visibleTiers(): array
    {
        return VisibilityLevel::allowedFor($this->roles());
    }

    /** @return list<string> */
    private function roles(): array
    {
        return $this->security->getUser()?->getRoles() ?? [];
    }
}
