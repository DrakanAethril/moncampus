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
     * The cahiers de texte of a program, for the course view (1b): one query rather than one per
     * séance, the screen displaying them all together.
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

    /**
     * The cahiers de texte of a given set of créneaux, attachments included - what the period screen
     * needs, where findForProgram() is scoped to one class and this one spans every class a teacher
     * has that week.
     *
     * @param list<LessonSession> $sessions
     *
     * @return list<LessonLog>
     */
    public function findForSessions(array $sessions): array
    {
        if ([] === $sessions) {
            return [];
        }

        return $this->createQueryBuilder('l')
            ->addSelect('a')
            ->leftJoin('l.attachments', 'a')
            ->where('l.lessonSession IN (:sessions)')
            ->setParameter('sessions', $sessions)
            ->getQuery()
            ->getResult();
    }
}
