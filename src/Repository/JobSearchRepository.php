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

    /** L'absence de ligne est l'état normal : une recherche ouverte n'en a pas. */
    public function isClosedFor(User $student): bool
    {
        return null !== $this->findOneBy(['student' => $student]);
    }

    /**
     * Les recherches closes d'une liste d'élèves, indexées par identifiant d'élève - l'écran 1a
     * affiche une classe entière, une requête par ligne serait absurde.
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
