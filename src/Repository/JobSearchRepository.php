<?php

namespace App\Repository;

use App\Entity\JobSearch;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobSearch>
 */
class JobSearchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobSearch::class);
    }

    /** Having no row is the normal state: an open job search does not have one. */
    public function isClosedFor(User $student): bool
    {
        return null !== $this->findOneBy(['student' => $student]);
    }

    /**
     * Closed searches for a list of students, indexed by student id - screen 1a shows a whole
     * class, and one query per row would be absurd.
     *
     * @param list<User> $students
     *
     * @return array<int, JobSearch>
     */
    public function findClosedIndexedByStudentId(array $students): array
    {
        if ([] === $students) {
            return [];
        }

        $indexed = [];

        foreach ($this->findBy(['student' => $students]) as $search) {
            $indexed[$search->getStudent()->getId()] = $search;
        }

        return $indexed;
    }
}
