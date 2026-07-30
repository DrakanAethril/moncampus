<?php

namespace App\Security;

use App\Entity\Program;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

// Shared visibility/access rule for the Section > Année scolaire > Classe nav menu (see
// StructureNavigationExtension) and the Program pages it links to (ProgramController) - a plain
// service rather than logic duplicated in both places.
class StructureAccessChecker
{
    public function __construct(private readonly Security $security)
    {
    }

    public function isStaff(): bool
    {
        return $this->security->isGranted('ROLE_ADMIN')
            || $this->security->isGranted('ROLE_STAFF')
            || $this->security->isGranted('ROLE_STAFF-LEAD');
    }

    // Is the person looking at the app a test account (User::$testUser)?
    public function isTestViewer(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $user->isTestUser();
    }

    /**
     * May this account deal with this Program at all, test-world-wise?
     *
     * Deliberately ASYMMETRIC. A test account is confined to test Programs and sees nothing else.
     * A real account is left exactly as it was: it still reaches test Programs, because somebody
     * has to be able to create and set one up, and the nav's TEST ZONE group exists for precisely
     * that - making this symmetric would put test Programs out of everyone's reach but the test
     * accounts, including the staff who have to build them.
     *
     * The screens that hide test Programs from day-to-day work (timetable, dashboards) do their
     * own strict "same side of the fence" check - there a test account swaps one world for the
     * other rather than adding to it.
     *
     * Checked BEFORE the staff bypass: a test admin that could still reach every real Program
     * would defeat the point of the flag. Clearing it restores everything, nothing is lost.
     */
    public function matchesTestMode(Program $program): bool
    {
        return !$this->isTestViewer() || $program->isTestProgram();
    }

    // Staff see/can reach every Program unconditionally; a student or teacher only sees/can
    // access one they're actually enrolled in or teaching (Program::$students/$teachers) - not
    // merely "holds the LDAP role tied to the program's cohort", which used to be the check here
    // and doesn't actually guarantee enrollment in this specific Program (the same cohort role
    // can span multiple school years/program instances, and a stale or reused LDAP role
    // shouldn't grant access to every one of them - it only ever meant "some program under this
    // cohort", not "this program").
    public function isProgramVisible(Program $program): bool
    {
        // Before the staff bypass - see matchesTestMode().
        if (!$this->matchesTestMode($program)) {
            return false;
        }

        if ($this->isStaff()) {
            return true;
        }

        $user = $this->security->getUser();

        return $user instanceof User
            && ($program->getStudents()->contains($user) || $program->getTeachers()->contains($user));
    }

    // Stricter than isProgramVisible() above: for teacher-only tools (e.g. the Outils > Tirage au
    // sort roulette) an enrolled student must NOT pass, unlike the general "can reach this
    // Program's pages" check.
    public function isProgramTeacher(Program $program): bool
    {
        if (!$this->matchesTestMode($program)) {
            return false;
        }

        if ($this->isStaff()) {
            return true;
        }

        $user = $this->security->getUser();

        return $user instanceof User && $program->getTeachers()->contains($user);
    }
}
