<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LessonLog;
use App\Entity\LessonSession;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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

    /**
     * The cahiers de texte already written for the same matière before this séance, most recent
     * first - where the class is at, which is what a teacher describing today's séance builds on.
     *
     * Only the ones that say something: an opened and abandoned cahier has nothing to tell.
     *
     * @return list<LessonLog>
     */
    public function findPreviousFilledForTopic(LessonSession $session, int $limit): array
    {
        if (null === $session->getTopic() || null === $session->getDay()) {
            return [];
        }

        return $this->createQueryBuilder('l')
            ->addSelect('s')
            ->innerJoin('l.lessonSession', 's')
            ->where('s.topic = :topic')
            ->andWhere('s.day < :day OR (s.day = :day AND s.startHour < :start)')
            ->andWhere("COALESCE(l.contenuRealise, '') != '' OR COALESCE(l.travailAvantDescription, '') != '' OR COALESCE(l.travailApresDescription, '') != ''")
            ->setParameter('topic', $session->getTopic())
            ->setParameter('day', $session->getDay(), Types::DATE_IMMUTABLE)
            ->setParameter('start', $session->getStartHour(), Types::TIME_IMMUTABLE)
            ->orderBy('s.day', 'DESC')
            ->addOrderBy('s.startHour', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
