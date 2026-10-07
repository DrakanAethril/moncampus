<?php

declare(strict_types=1);

namespace App\Service\Wall;

use App\Entity\User;
use App\Entity\Wall;
use App\Repository\ProgramRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Who reads a student's wall without being on it: **the teachers of the class**.
 *
 * A wall a student owns is read by whoever teaches an active class of one of the students on it -
 * its owner, or a classmate named on it. That is the whole rule, and it is deliberately narrow:
 *
 * - it follows the *student*, so a teacher's own wall is supervised by nobody, even when a class
 *   is on it - a colleague of the same class has no more business on it than before;
 * - it follows the *class*, so the administration and the other teachers of the establishment do
 *   not read a student's wall on the strength of their role;
 * - it reads and nothing else (App\Enum\WallRole::Supervisor): no card, no comment, no setting.
 *
 * A wall is asked many times in one request - once per card when it is drawn - so the answer is
 * kept for the request, and dropped between two: the container outlives a request in worker mode.
 */
class WallSupervisors implements ResetInterface
{
    /** @var array<string, bool> */
    private array $answers = [];

    public function __construct(private readonly ProgramRepository $programs)
    {
    }

    public function supervises(Wall $wall, User $user): bool
    {
        if (!self::isStudent($wall->getOwner()) || $wall->isOwnedBy($user)) {
            return false;
        }

        $key = ($wall->getId() ?? 'new'.spl_object_id($wall)).':'.($user->getId() ?? 'new'.spl_object_id($user));

        return $this->answers[$key] ??= $this->programs->teachesAnyOf($user, self::studentsOn($wall));
    }

    /**
     * The students a student's wall is about: its owner and the classmates named on it. A teacher
     * the student invited is on the wall too, but is not who it is supervised for.
     *
     * @return list<User>
     */
    public static function studentsOn(Wall $wall): array
    {
        $students = [$wall->getOwner()];
        foreach ($wall->getMembers() as $member) {
            if (self::isStudent($member)) {
                $students[] = $member;
            }
        }

        return $students;
    }

    /** A student and nothing more: an account that also teaches or administers is personnel. */
    public static function isStudent(User $user): bool
    {
        return \in_array('ROLE_STUDENT', $user->getRoles(), true) && !WallAccess::isStaff($user);
    }

    public function reset(): void
    {
        $this->answers = [];
    }
}
