<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Security\StructureAccessChecker;

/**
 * Who a given person may put into a wiki - the entity side of
 * App\Service\WikiAccess::mayAssignProgram(), which holds the rule itself.
 *
 * | Composer                  | Student members                    | Classes             | Colleagues            |
 * |---------------------------|------------------------------------|---------------------|-----------------------|
 * | Teacher                   | students of the programs they teach | the programs taught | any teacher or staff  |
 * | Staff / staff-lead / admin| any student                        | any program         | any teacher or staff  |
 *
 * A wiki spanning several classes is therefore possible for anyone, but a teacher can only build
 * one across classes they actually teach; an interdisciplinary wiki mixing filières is composed by
 * staff. Nothing in the model forbids the shape - Wiki::$members is a free ManyToMany, never scoped
 * to a program - so this is a rule about who assembles an audience, not a limit of the data model.
 *
 * The asymmetry with *reading* is intended: every teacher reaches every student wiki, including a
 * cross-class one they could not have composed. Supervision is broad; composition is not.
 *
 * Both halves of this class matter. The search feeds the picker, and the check is what the form
 * calls again at save: a picker is a convenience, never the control.
 */
class WikiAudienceScope
{
    public function __construct(
        private readonly ProgramRepository $programs,
        private readonly UserRepository $users,
        private readonly StructureAccessChecker $accessChecker,
        private readonly WikiAccess $access,
    ) {
    }

    /** @return list<Program> */
    public function assignablePrograms(User $composer): array
    {
        if ($this->accessChecker->isStaff()) {
            return $this->programs->findAllActiveWithStudents();
        }

        return $this->programs->findAllForTeacher($composer);
    }

    public function mayAssign(User $composer, Program $program): bool
    {
        return $this->access->mayAssignProgram(
            $composer->getRoles(),
            $this->teaches($composer, $program),
        );
    }

    /**
     * May this person be named as a member? Colleagues are open to any composer; students are
     * scoped to the composer's own classes unless they are staff.
     */
    public function mayAddMember(User $composer, User $candidate): bool
    {
        foreach (UserRepository::NON_ADDRESSABLE_ROLES as $excluded) {
            if (\in_array($excluded, $candidate->getRoles(), true)) {
                return false;
            }
        }

        if (!\in_array('ROLE_STUDENT', $candidate->getRoles(), true)) {
            // A colleague - any teacher or staff member, for any composer.
            return \in_array('ROLE_TEACHER', $candidate->getRoles(), true)
                || $this->access->mayAssignProgram($candidate->getRoles(), false);
        }

        if ($this->accessChecker->isStaff()) {
            return true;
        }

        foreach ($this->programs->findAllForTeacher($composer) as $program) {
            if ($program->getStudents()->contains($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The picker's own list: everybody this composer may add, matching the typed term.
     *
     * @return list<User>
     */
    public function candidates(User $composer, ?string $search, int $limit = 20): array
    {
        $matches = [];

        foreach ($this->users->searchActive($search, $limit * 4) as $candidate) {
            if ($this->mayAddMember($composer, $candidate)) {
                $matches[] = $candidate;
            }

            if (\count($matches) === $limit) {
                break;
            }
        }

        return $matches;
    }

    private function teaches(User $composer, Program $program): bool
    {
        return $program->getTeachers()->contains($composer);
    }
}
