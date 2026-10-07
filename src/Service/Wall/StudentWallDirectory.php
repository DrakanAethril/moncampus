<?php

declare(strict_types=1);

namespace App\Service\Wall;

use App\Entity\Program;
use App\Entity\User;
use App\Entity\Wall;
use App\Repository\WallRepository;

/**
 * « Murs étudiants », one class at a time: the walls its students own, in two sections.
 *
 * - **Murs des étudiants** - a wall one student of the class keeps on their own (a teacher they
 *   invited does not make it a group's), listed under its owner.
 * - **Murs de groupe** - a student's wall with at least one other student on it, as soon as one of
 *   those students is of this class: the wall of a group that straddles two classes is listed in
 *   both, which is also who supervises it (App\Service\Wall\WallSupervisors).
 *
 * Only walls a student owns are here. A teacher's wall shared with the class is the teacher's, and
 * is reached from « Partagés avec moi » by whoever it was shared with.
 *
 * @phpstan-type StudentWalls array{student: User, walls: list<Wall>}
 * @phpstan-type GroupWall array{wall: Wall, students: list<User>}
 * @phpstan-type ClassWalls array{individual: list<StudentWalls>, groups: list<GroupWall>}
 */
final class StudentWallDirectory
{
    public function __construct(private readonly WallRepository $walls)
    {
    }

    /** @return ClassWalls */
    public function forClass(Program $program): array
    {
        $classStudents = $program->getStudents()->toArray();
        $individual = [];
        $groups = [];

        foreach ($this->walls->findInvolving(array_values($classStudents)) as $wall) {
            $owner = $wall->getOwner();
            if (!WallSupervisors::isStudent($owner)) {
                continue;
            }

            $students = WallSupervisors::studentsOn($wall);
            if (\count($students) > 1) {
                usort($students, self::byName(...));
                $groups[] = ['wall' => $wall, 'students' => $students];
            } elseif (\in_array($owner, $classStudents, true)) {
                $individual[(int) $owner->getId()] ??= ['student' => $owner, 'walls' => []];
                $individual[(int) $owner->getId()]['walls'][] = $wall;
            }
        }

        $individual = array_values($individual);
        usort($individual, static fn (array $a, array $b): int => self::byName($a['student'], $b['student']));

        return ['individual' => $individual, 'groups' => $groups];
    }

    private static function byName(User $a, User $b): int
    {
        return strcasecmp(($a->getLastname() ?? '').' '.($a->getFirstname() ?? '').' '.$a->getUsername(), ($b->getLastname() ?? '').' '.($b->getFirstname() ?? '').' '.$b->getUsername());
    }
}
