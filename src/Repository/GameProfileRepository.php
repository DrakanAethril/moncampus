<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameProfile>
 */
class GameProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameProfile::class);
    }

    public function findForStudent(User $student): ?GameProfile
    {
        return $this->findOneBy(['student' => $student]);
    }

    /**
     * @param list<User> $students
     *
     * @return array<int, GameProfile> keyed by student id
     */
    public function findForStudents(array $students): array
    {
        if ([] === $students) {
            return [];
        }

        /** @var list<GameProfile> $profiles */
        $profiles = $this->createQueryBuilder('p')
            ->where('p.student IN (:students)')
            ->setParameter('students', $students)
            ->getQuery()
            ->getResult();

        $byStudent = [];
        foreach ($profiles as $profile) {
            $byStudent[(int) $profile->getStudent()->getId()] = $profile;
        }

        return $byStudent;
    }
}
