<?php

namespace App\Service;

use App\Entity\Program;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;

/**
 * Server-side enforcement of the messaging permission matrix - see
 * design/validated/internal-messaging.md. Every method re-derives the answer from the sender's
 * actual roles/program memberships; nothing here trusts client input beyond "which option did
 * they pick", which is then validated against this.
 */
class MessagingAccessChecker
{
    private const string ROLE_EXTERNAL = 'ROLE_EXTERNAL';
    private const string ROLE_TEACHER = 'ROLE_TEACHER';

    /** @var list<string> */
    private const array STAFF_ROLES = ['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function isStaff(User $user): bool
    {
        return [] !== array_intersect(self::STAFF_ROLES, $user->getRoles());
    }

    private function isTeacher(User $user): bool
    {
        return \in_array(self::ROLE_TEACHER, $user->getRoles(), true);
    }

    // Staff/staff-lead/admin get the Program shortcut (unscoped to any one Program, and free to
    // combine students/teachers), plus SchoolWide. Teachers get the Program shortcut too, scoped
    // to their own Programs only (see programsForAudienceShortcut() below) - no SchoolWide.
    // Everyone else (students) gets no shortcut at all: composing to more than one person is only
    // ever a manual pick, never a broadcast power.
    /** @return list<MessageAudienceType> */
    public function allowedAudienceTypes(User $sender): array
    {
        if ($this->isStaff($sender)) {
            return [MessageAudienceType::Program, MessageAudienceType::SchoolWide, MessageAudienceType::Manual];
        }

        if ($this->isTeacher($sender)) {
            return [MessageAudienceType::Program, MessageAudienceType::Manual];
        }

        return [MessageAudienceType::Manual];
    }

    /** @return list<Program> */
    public function programsForAudienceShortcut(User $sender): array
    {
        if ($this->isStaff($sender)) {
            return $this->programRepository->findActiveForNav();
        }

        if ($this->isTeacher($sender)) {
            return $this->programRepository->findAllForTeacher($sender);
        }

        return [];
    }

    // The permission matrix for a single individual recipient - also what every Manual
    // multi-recipient pick is validated against, one user at a time.
    public function canMessageIndividually(User $sender, User $target): bool
    {
        if ($sender === $target || \in_array(self::ROLE_EXTERNAL, $target->getRoles(), true)) {
            return false;
        }

        if ($this->isStaff($sender)) {
            return true;
        }

        if ($this->isTeacher($sender)) {
            if ($this->isStaff($target) || $this->isTeacher($target)) {
                return true;
            }

            foreach ($this->programRepository->findAllForTeacher($sender) as $program) {
                if ($program->getStudents()->contains($target)) {
                    return true;
                }
            }

            return false;
        }

        // A student (or anyone else without teacher/staff roles): their own Program's teachers,
        // or any staff member - never another student, never anyone else's teacher.
        if ($this->isStaff($target)) {
            return true;
        }

        $program = $this->programRepository->findActiveForStudent($sender);

        return null !== $program && $program->getTeachers()->contains($target);
    }

    // Backs the recipients-search ajax endpoint. Unlimited-then-filter-then-slice, same
    // "fine at this scale" convention as UserRepository::findActiveMatchingAnyRole() - a school
    // roster, not millions of rows.
    /** @return list<User> */
    public function searchCandidateRecipients(User $sender, ?string $search, int $limit): array
    {
        $candidates = $this->userRepository->findActiveExcludingRole(self::ROLE_EXTERNAL, [$sender->getId()], $search);
        $matching = array_values(array_filter(
            $candidates,
            fn (User $candidate): bool => $this->canMessageIndividually($sender, $candidate),
        ));

        return \array_slice($matching, 0, $limit);
    }

    // Resolves manually-submitted recipient ids back to Users, re-validating every one against
    // the permission matrix rather than trusting the client - a forged id for someone the sender
    // isn't allowed to reach is silently dropped, same security role as
    // UserRepository::findByIdsForProgram() in ProgramAssignmentController::form().
    /**
     * @param list<int> $ids
     *
     * @return list<User>
     */
    public function resolveManualRecipients(User $sender, array $ids): array
    {
        return array_values(array_filter(
            $this->userRepository->findByIds($ids),
            fn (User $candidate): bool => $this->canMessageIndividually($sender, $candidate),
        ));
    }
}
