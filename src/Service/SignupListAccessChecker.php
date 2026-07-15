<?php

namespace App\Service;

use App\Entity\Program;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;

/**
 * Who may create a sign-up list, and with which audience shortcuts - same shape as
 * MessagingAccessChecker, one layer up in the messaging feature, deliberately kept as a separate
 * service rather than merged into it: creating a sign-up list isn't "sending a message", and
 * mixing the two permission matrices into one class would make either one harder to reason about
 * independently even though today they happen to agree almost exactly.
 */
class SignupListAccessChecker
{
    private const string ROLE_TEACHER = 'ROLE_TEACHER';

    /** @var list<string> */
    private const array STAFF_ROLES = ['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly UserRepository $userRepository,
        private readonly MessagingAccessChecker $messagingAccessChecker,
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

    public function canCreate(User $user): bool
    {
        return $this->isStaff($user) || $this->isTeacher($user);
    }

    // Staff/staff-lead/admin get the Program shortcut (unscoped, any program, free to combine
    // students/teachers) plus the AllStudents/AllTeachers/AllStaff broadcasts and Manual. Teachers
    // get the Program shortcut scoped to their own programs only (programsForAudienceShortcut()
    // below) plus Manual - no broadcast shortcuts. Same matrix as
    // MessagingAccessChecker::allowedAudienceTypes(), see that class's docblock.
    /** @return list<MessageAudienceType> */
    public function allowedAudienceTypes(User $user): array
    {
        if ($this->isStaff($user)) {
            return [MessageAudienceType::Program, MessageAudienceType::AllStudents, MessageAudienceType::AllTeachers, MessageAudienceType::AllStaff, MessageAudienceType::Manual];
        }

        if ($this->isTeacher($user)) {
            return [MessageAudienceType::Program, MessageAudienceType::Manual];
        }

        return [];
    }

    /** @return list<Program> */
    public function programsForAudienceShortcut(User $user): array
    {
        if ($this->isStaff($user)) {
            return $this->programRepository->findActiveForNav();
        }

        if ($this->isTeacher($user)) {
            return $this->programRepository->findAllForTeacher($user);
        }

        return [];
    }

    // Manual-pick reach for the recipients-search ajax endpoint: staff can pick any active
    // non-external user (same as AnnouncementController/AgendaController's own recipients-search,
    // unrestricted since only staff/teachers ever reach this at all); a teacher is scoped to
    // whoever they could already reach in messaging (own program's students, any teacher, any
    // staff) - reusing MessagingAccessChecker::canMessageIndividually() rather than inventing a
    // second, slightly-different reachability rule for the same underlying question ("who can this
    // person plausibly vouch is eligible for something").
    /** @return list<User> */
    public function searchManualCandidates(User $user, ?string $search, int $limit): array
    {
        if ($this->isStaff($user)) {
            return \array_slice($this->userRepository->findActiveExcludingRole('ROLE_EXTERNAL', [], $search), 0, $limit);
        }

        return \array_slice($this->messagingAccessChecker->searchCandidateRecipients($user, $search, $limit * 2), 0, $limit);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<User>
     */
    public function resolveManualRecipients(User $user, array $ids): array
    {
        if ($this->isStaff($user)) {
            return $this->userRepository->findByIds($ids);
        }

        return $this->messagingAccessChecker->resolveManualRecipients($user, $ids);
    }
}
