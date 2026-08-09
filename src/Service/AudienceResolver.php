<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AudienceTargetable;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves any App\Entity\AudienceTargetable (MessageThread, Announcement, AgendaEvent) to the
 * actual list of Users it reaches - same role as App\Service\AssignmentAudienceResolver, one
 * level up in the Assignment-submission-box feature.
 *
 * Two entry points that must always agree: resolveRecipients() builds the list, isVisibleTo()
 * answers membership for one user without building it. Callers that only need a yes/no - and
 * App\Security\Voter\AudienceTargetableVoter, which every screen goes through, is one - should
 * always take the second.
 */
class AudienceResolver implements ResetInterface
{
    private const string ROLE_STUDENT = 'ROLE_STUDENT';
    private const string ROLE_TEACHER = 'ROLE_TEACHER';

    /** @var list<string> */
    private const array STAFF_ROLES = ['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    /**
     * The ids of the Programs the current user belongs to, keyed by user id - one query each per
     * request rather than per target, which is the whole point of isVisibleTo() below.
     *
     * Memoised state on a shared service, so this class implements ResetInterface for the same
     * reason App\Twig\StructureNavigationExtension does: under FrankenPHP worker mode the instance
     * outlives the request, and without a reset the first request's answer would keep being served
     * to every later one handled by that worker - here that would mean showing one user another
     * user's events, so it is a correctness matter and not just staleness.
     *
     * @var array<int, list<int>>
     */
    private array $studentProgramIds = [];

    /** @var array<int, list<int>> */
    private array $teacherProgramIds = [];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ProgramRepository $programRepository,
    ) {
    }

    // $exclude drops one user from the resolved list regardless of audience type - used by
    // MessageController for the sender, who isn't a recipient of their own message. Announcement/
    // AgendaEvent have no such concept (their creator is meant to see their own post/event too),
    // so callers there simply omit it.
    /** @return list<User> */
    public function resolveRecipients(AudienceTargetable $target, ?User $exclude = null): array
    {
        $excludedIds = null !== $exclude ? [$exclude->getId()] : [];

        $resolved = match ($target->getAudienceType()) {
            MessageAudienceType::Program => $this->resolveProgramAudience($target),
            // Outside accounts (ROLE_TUTOR/ROLE_EXTERNAL) never match ROLE_STUDENT/ROLE_TEACHER/
            // the staff roles, so they're never reachable through any of these three, same as they
            // never were through the old SchoolWide case - see
            // design/validated/internal-messaging.md.
            MessageAudienceType::AllStudents => $this->userRepository->findActiveMatchingRoles([self::ROLE_STUDENT], $excludedIds),
            MessageAudienceType::AllTeachers => $this->userRepository->findActiveMatchingRoles([self::ROLE_TEACHER], $excludedIds),
            MessageAudienceType::AllStaff => $this->userRepository->findActiveMatchingAnyRole(self::STAFF_ROLES, $excludedIds),
            MessageAudienceType::Manual => $target->getManualRecipients()->toArray(),
            null => [],
        };

        return null !== $exclude ? array_values(array_filter($resolved, static fn (User $user): bool => $user !== $exclude)) : $resolved;
    }

    /**
     * Whether one user is in the audience - answered without building the audience.
     *
     * This used to be `in_array($user, $this->resolveRecipients($target), true)`, which for a
     * Program target loaded every student and every teacher of every Program named, and for a
     * role-wide one loaded the school's entire active roster, only to look for a single entry.
     * Called once per target it is quadratic in the worst case: the home dashboard was measured
     * resolving 64 events' full audiences to display four of them.
     *
     * Each branch answers the same question the corresponding branch of resolveRecipients() would,
     * from the same source of truth, reading only what the question needs. The two must never
     * disagree - App\Tests\Service\AudienceResolverTest asserts exactly that across a matrix of
     * audience types and users, and is the reason this is safe to keep separate.
     */
    public function isVisibleTo(AudienceTargetable $target, User $user): bool
    {
        // Mirrors the `inactiveDate IS NULL` that UserRepository::findActiveCandidates() applies
        // to the three role-wide branches. The Program and Manual branches never went through it,
        // so it deliberately does not gate them: an inactivated account still linked to a Program
        // stayed in that audience before, and still does.
        $isActive = null === $user->getInactiveDate();

        return match ($target->getAudienceType()) {
            MessageAudienceType::Program => $this->belongsToProgramAudience($target, $user),
            // findActiveMatchingRoles() requires ALL the roles asked for; with one role that is
            // simply "holds it".
            MessageAudienceType::AllStudents => $isActive && \in_array(self::ROLE_STUDENT, $user->getRoles(), true),
            MessageAudienceType::AllTeachers => $isActive && \in_array(self::ROLE_TEACHER, $user->getRoles(), true),
            // findActiveMatchingAnyRole(), by contrast, needs only one of the three to match.
            MessageAudienceType::AllStaff => $isActive && [] !== array_intersect(self::STAFF_ROLES, $user->getRoles()),
            MessageAudienceType::Manual => $target->getManualRecipients()->contains($user),
            null => false,
        };
    }

    /**
     * Set intersection between the Programs the target names and those the user belongs to, taken
     * from the join tables directly instead of walking each Program's student/teacher collections.
     *
     * The include flags are checked before the membership lookup so an audience that includes
     * neither costs no query at all, and so a target naming only Programs the user is unrelated to
     * still costs one lookup rather than one per Program.
     */
    private function belongsToProgramAudience(AudienceTargetable $target, User $user): bool
    {
        $targetedIds = [];
        foreach ($target->getPrograms() as $program) {
            $id = $program->getId();
            if (null !== $id) {
                $targetedIds[] = $id;
            }
        }

        if ([] === $targetedIds) {
            return false;
        }

        if ($target->isIncludeStudents() && [] !== array_intersect($targetedIds, $this->programIdsAsStudent($user))) {
            return true;
        }

        return $target->isIncludeTeachers() && [] !== array_intersect($targetedIds, $this->programIdsAsTeacher($user));
    }

    /** @return list<int> */
    private function programIdsAsStudent(User $user): array
    {
        $userId = $user->getId();

        // An unpersisted User has no row in the join table and so belongs to no Program - and
        // there would be no id to memoise under either.
        if (null === $userId) {
            return [];
        }

        return $this->studentProgramIds[$userId] ??= $this->programRepository->findIdsWithUserAsStudent($user);
    }

    /** @return list<int> */
    private function programIdsAsTeacher(User $user): array
    {
        $userId = $user->getId();

        if (null === $userId) {
            return [];
        }

        return $this->teacherProgramIds[$userId] ??= $this->programRepository->findIdsWithUserAsTeacher($user);
    }

    public function reset(): void
    {
        $this->studentProgramIds = [];
        $this->teacherProgramIds = [];
    }

    // Union of students/teachers across every selected Program, deduplicated by id (a user
    // attached to more than one selected Program, e.g. a teacher across two of them, must only
    // appear once) - independent include flags mean "students only", "teachers only", or "both" of
    // each selected Program, see AudienceTargetable's docblock.
    /** @return list<User> */
    private function resolveProgramAudience(AudienceTargetable $target): array
    {
        $users = [];

        foreach ($target->getPrograms() as $program) {
            if ($target->isIncludeStudents()) {
                foreach ($program->getStudents() as $student) {
                    $users[$student->getId()] = $student;
                }
            }

            if ($target->isIncludeTeachers()) {
                foreach ($program->getTeachers() as $teacher) {
                    $users[$teacher->getId()] = $teacher;
                }
            }
        }

        return array_values($users);
    }
}
