<?php

namespace App\Repository;

use App\Entity\SequenceTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SequenceTemplate>
 */
class SequenceTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SequenceTemplate::class);
    }

    /** @return list<SequenceTemplate> */
    public function findForTeacher(User $teacher): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('c', 'o')
            ->leftJoin('s.cohort', 'c')
            ->leftJoin('s.option', 'o')
            ->where('s.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('s.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
