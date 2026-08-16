<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\WikiType;
use App\Repository\UserRepository;

/**
 * The single answer to "may this person edit / manage / delete this wiki".
 *
 * One invariant produces the whole table:
 *
 * > A wiki a student can reach, every teacher can reach. A wiki with no student in it belongs to
 * > its members alone.
 *
 * Supervision follows students - there is no visibility switch to find, tick or forget, which is
 * also why the screens have to *say* the current regime in words: changing the audience changes the
 * visibility as a side effect, and that is the rule's one sharp edge.
 *
 * | Wiki                                   | Edit                                       | Manage         | Delete            |
 * |----------------------------------------|--------------------------------------------|----------------|-------------------|
 * | Personal, owner is a student           | the student + every teacher / staff        | teachers/staff | ROLE_ADMIN        |
 * | Personal, owner is a teacher or staff  | the owner only, plus ROLE_ADMIN            | idem           | idem              |
 * | Shared with a student audience         | members + assigned classes + every teacher | teachers/staff | creator + admin   |
 * | Shared between colleagues              | its members + its creator, plus ROLE_ADMIN | every member   | creator + admin   |
 *
 * The last row is the sharp one and is not an oversight: staff, ROLE_STAFF-LEAD included, are kept
 * out of an espace de travail between colleagues, because it is not administrative material. The
 * ROLE_ADMIN door exists for recovering the work of someone who has left the school, not for
 * routine reading - and a teacher's own personal wiki follows exactly the same rule, one rule for
 * both cases.
 *
 * Everything is primitives, so the rule is testable without an entity graph - see
 * App\Service\WikiSubject and App\Service\WikiViewer. App\Security\Voter\WikiVoter is the only
 * thing that maps entities onto it; nothing else re-implements any of this.
 */
class WikiAccess
{
    public function mayEdit(WikiSubject $wiki, WikiViewer $viewer): bool
    {
        if (!$this->isEligible($viewer)) {
            return false;
        }

        if ($this->isAdmin($viewer->roles)) {
            return true;
        }

        if (WikiType::Personal === $wiki->type) {
            if ($viewer->id === $wiki->ownerId) {
                return true;
            }

            // Supervision follows students, and only students: a colleague's personal wiki is
            // theirs alone.
            return $wiki->ownerIsStudent && $this->isSupervision($viewer->roles);
        }

        if ($wiki->hasStudentAudience) {
            return $this->isMember($wiki, $viewer)
                || $viewer->isAssignedProgramStudent
                || $this->isSupervision($viewer->roles);
        }

        return $this->isMember($wiki, $viewer) || $viewer->id === $wiki->creatorId;
    }

    public function mayManage(WikiSubject $wiki, WikiViewer $viewer): bool
    {
        if (!$this->isEligible($viewer)) {
            return false;
        }

        if ($this->isAdmin($viewer->roles)) {
            return true;
        }

        if (WikiType::Personal === $wiki->type) {
            // A student's own wiki is managed by the personnel that supervises it; a teacher's is
            // managed by its owner. Note this deliberately leaves the student out of their own
            // wiki's settings screen - a personal wiki has neither members nor classes to compose,
            // so nothing on that screen is theirs to change.
            return $wiki->ownerIsStudent ? $this->isSupervision($viewer->roles) : $viewer->id === $wiki->ownerId;
        }

        if ($wiki->hasStudentAudience) {
            return $this->isSupervision($viewer->roles);
        }

        // A collaborative space: every member composes it - title, members, archive.
        return $this->isMember($wiki, $viewer) || $viewer->id === $wiki->creatorId;
    }

    public function mayDelete(WikiSubject $wiki, WikiViewer $viewer): bool
    {
        if (!$this->isEligible($viewer)) {
            return false;
        }

        if ($this->isAdmin($viewer->roles)) {
            return true;
        }

        if (WikiType::Personal === $wiki->type) {
            // A student's personal wiki is an admin's call and nobody else's - a teacher manages
            // it, but removing a year of somebody's work is not a management act.
            return !$wiki->ownerIsStudent && $viewer->id === $wiki->ownerId;
        }

        // The trash restores pages, never the wiki itself, so removing one stays with the person
        // who created it.
        return $viewer->id === $wiki->creatorId;
    }

    /**
     * The live "does this wiki have a student audience" test - never stored, so that changing the
     * audience moves the wiki between the two regimes on the spot.
     *
     * @param list<list<string>> $memberRoles the roles of each member, in any order
     */
    public function hasStudentAudience(int $assignedProgramCount, array $memberRoles): bool
    {
        if ($assignedProgramCount > 0) {
            return true;
        }

        foreach ($memberRoles as $roles) {
            if (\in_array('ROLE_STUDENT', $roles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * May this person put a class - and therefore its students - into a wiki?
     *
     * Reaching a wiki and composing its audience are scoped differently on purpose: a teacher may
     * only build one across the classes they actually teach, while staff compose across filières.
     * The asymmetry with reading is intended and must not be "fixed" into symmetry - narrowing the
     * read side would break supervision, and widening this side would let any teacher enrol any
     * student in the school.
     *
     * @param list<string> $roles
     */
    public function mayAssignProgram(array $roles, bool $teachesProgram): bool
    {
        if ($this->isStaffRole($roles)) {
            return true;
        }

        return $teachesProgram && \in_array('ROLE_TEACHER', $roles, true);
    }

    /**
     * Outside accounts are excluded from the feature entirely - no wiki of their own, never a
     * member, never a reader. Same posture as messaging, and the same list, so the two cannot
     * drift apart.
     */
    private function isEligible(WikiViewer $viewer): bool
    {
        if (null === $viewer->id) {
            return false;
        }

        foreach (UserRepository::NON_ADDRESSABLE_ROLES as $excluded) {
            if (\in_array($excluded, $viewer->roles, true)) {
                return false;
            }
        }

        return true;
    }

    private function isMember(WikiSubject $wiki, WikiViewer $viewer): bool
    {
        return null !== $viewer->id && \in_array($viewer->id, $wiki->memberIds, true);
    }

    /** @param list<string> $roles */
    private function isSupervision(array $roles): bool
    {
        return \in_array('ROLE_TEACHER', $roles, true) || $this->isStaffRole($roles);
    }

    /** @param list<string> $roles */
    private function isStaffRole(array $roles): bool
    {
        return \in_array('ROLE_ADMIN', $roles, true)
            || \in_array('ROLE_STAFF', $roles, true)
            || \in_array('ROLE_STAFF-LEAD', $roles, true);
    }

    /** @param list<string> $roles */
    private function isAdmin(array $roles): bool
    {
        return \in_array('ROLE_ADMIN', $roles, true);
    }
}
