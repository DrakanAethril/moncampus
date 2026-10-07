<?php

declare(strict_types=1);

namespace App\Service\Wall;

use App\Entity\Program;
use App\Entity\User;
use App\Entity\Wall;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Service\ClassBoard\ClassBoardPrograms;

/**
 * Whom a wall's owner may open it to (« Partager »), and the one place a sharing is written.
 *
 * | The owner is…        | …may add classes                                    | …may add people                                          |
 * |----------------------|-----------------------------------------------------|----------------------------------------------------------|
 * | teaching or staff    | the classes they teach (the administration: all)    | colleagues - any active teaching or staff account        |
 * | a student            | none                                                | classmates and the teachers of their own active classes  |
 *
 * The classes are the virtual board's own list (App\Service\ClassBoard\ClassBoardPrograms): one
 * definition of « the classes this person addresses », test zone included.
 *
 * The rule bounds what is *added*. Somebody already on a wall stays on it until the owner takes
 * them off - a student who changed class in January does not silently lose the wall they were
 * working on, and a class one no longer teaches is still listed so that it can be unticked.
 */
final class WallAudience
{
    public function __construct(
        private readonly ProgramRepository $programs,
        private readonly UserRepository $users,
        private readonly ClassBoardPrograms $classes,
    ) {
    }

    /** @return list<Program> */
    public function classesFor(User $owner): array
    {
        return WallAccess::isStaff($owner) ? $this->classes->linkable($owner) : [];
    }

    /**
     * The people offered by the picker, narrowed by what was typed.
     *
     * @return list<User>
     */
    public function peopleFor(User $owner, string $search = '', int $limit = 20): array
    {
        if (WallAccess::isStaff($owner)) {
            return \array_slice($this->users->findActiveMatchingAnyRole(WallAccess::STAFF_ROLES, [(int) $owner->getId()], $search), 0, $limit);
        }

        $needle = mb_strtolower(trim($search));
        $people = array_filter(
            $this->classmatesAndTeachersOf($owner),
            static fn (User $person): bool => '' === $needle
                || str_contains(mb_strtolower(($person->getDisplayName() ?? '').' '.$person->getUsername()), $needle),
        );
        usort($people, static fn (User $a, User $b): int => strcasecmp($a->getDisplayName() ?? $a->getUsername(), $b->getDisplayName() ?? $b->getUsername()));

        return \array_slice($people, 0, $limit);
    }

    public function mayInvite(User $owner, User $person): bool
    {
        if ($person === $owner || null !== $person->getInactiveDate()) {
            return false;
        }

        if (WallAccess::isStaff($owner)) {
            return WallAccess::isStaff($person);
        }

        return \in_array($person, $this->classmatesAndTeachersOf($owner), true);
    }

    /**
     * Replaces the wall's audience by the classes and people named. An id that is neither already
     * there nor offered to this owner is dropped, never refused: the form only ever lists what is
     * offered, so such an id was typed by hand.
     *
     * @param list<int> $programIds
     * @param list<int> $memberIds
     */
    public function share(Wall $wall, array $programIds, array $memberIds): void
    {
        $owner = $wall->getOwner();

        foreach ($wall->getPrograms()->toArray() as $program) {
            if (!\in_array($program->getId(), $programIds, true)) {
                $wall->removeProgram($program);
            }
        }
        foreach ($this->classesFor($owner) as $program) {
            if (\in_array($program->getId(), $programIds, true)) {
                $wall->addProgram($program);
            }
        }

        foreach ($wall->getMembers()->toArray() as $member) {
            if (!\in_array($member->getId(), $memberIds, true)) {
                $wall->removeMember($member);
            }
        }
        foreach ($this->users->findByIds($memberIds) as $person) {
            if ($this->mayInvite($owner, $person)) {
                $wall->addMember($person);
            }
        }
    }

    /** @return list<User> */
    private function classmatesAndTeachersOf(User $student): array
    {
        $people = [];
        foreach ($this->programs->findAllActiveForStudent($student) as $program) {
            foreach ([...$program->getStudents(), ...$program->getTeachers()] as $person) {
                if ($person !== $student && null === $person->getInactiveDate()) {
                    $people[(int) $person->getId()] = $person;
                }
            }
        }

        return array_values($people);
    }
}
