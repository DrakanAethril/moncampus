<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LessonLog;
use App\Entity\LessonSession;
use App\Entity\Program;
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

    /**
     * Les cahiers de texte d'une formation, pour la vue cours (1b) : une requête plutôt qu'une par
     * séance, l'écran les affichant toutes ensemble.
     *
     * @return list<LessonLog>
     */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('s', 'a')
            ->innerJoin('l.lessonSession', 's')
            ->leftJoin('l.attachments', 'a')
            ->where('s.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();
    }
}
