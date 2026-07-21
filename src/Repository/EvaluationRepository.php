<?php

namespace App\Repository;

use App\Entity\Evaluation;
use App\Entity\Topic;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evaluation>
 */
class EvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evaluation::class);
    }

    /** @return list<Evaluation> */
    public function findActiveForTopicOrderedByDate(Topic $topic): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.topic = :topic')
            ->andWhere('e.inactiveDate IS NULL')
            ->setParameter('topic', $topic)
            ->leftJoin('e.rubricSections', 'rs')->addSelect('rs')
            ->leftJoin('rs.questions', 'rq')->addSelect('rq')
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
