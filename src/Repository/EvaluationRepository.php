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

    /**
     * The typed (D/F/S) evaluations the Progression calendars plot - only those carrying a
     * nature, since an untyped evaluation is a plain Carnet de notes row this module knows
     * nothing about (see App\Enum\EvaluationNature).
     *
     * @param list<Topic> $topics
     *
     * @return list<Evaluation>
     */
    public function findTypedForTopicsBetween(array $topics, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $topics) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->addSelect('t', 'ls')
            ->innerJoin('e.topic', 't')
            ->leftJoin('e.lessonSession', 'ls')
            ->andWhere('e.topic IN (:topics)')
            ->andWhere('e.nature IS NOT NULL')
            ->andWhere('e.inactiveDate IS NULL')
            ->andWhere('e.date >= :from')
            ->andWhere('e.date <= :to')
            ->setParameter('topics', $topics)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
