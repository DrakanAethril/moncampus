<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EcoleDirecteStudentLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EcoleDirecteStudentLink>
 */
class EcoleDirecteStudentLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EcoleDirecteStudentLink::class);
    }

    public function findForStudent(User $student): ?EcoleDirecteStudentLink
    {
        return $this->findOneBy(['student' => $student]);
    }

    /**
     * @param list<User> $students
     *
     * @return array<int, EcoleDirecteStudentLink> keyed by MonCampus student id
     */
    public function findForStudents(array $students): array
    {
        if ([] === $students) {
            return [];
        }

        $links = [];
        foreach ($this->findBy(['student' => $students]) as $link) {
            $id = $link->getStudent()->getId();
            if (null !== $id) {
                $links[$id] = $link;
            }
        }

        return $links;
    }
}
