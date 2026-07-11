<?php

namespace App\Repository;

use App\Entity\LessonLog;
use App\Entity\LessonSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LessonLog>
 */
class LessonLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LessonLog::class);
    }

    public function findOneBySession(LessonSession $session): ?LessonLog
    {
        return $this->createQueryBuilder('l')
            ->addSelect('a')
            ->leftJoin('l.attachments', 'a')
            ->where('l.lessonSession = :session')
            ->setParameter('session', $session)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
