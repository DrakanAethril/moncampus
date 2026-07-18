<?php

namespace App\Repository;

use App\Entity\QuizLiveParticipant;
use App\Entity\QuizLiveSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizLiveParticipant>
 */
class QuizLiveParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizLiveParticipant::class);
    }

    // Used by QuizLiveSessionService::join()/submitAnswer() - unique per (session, student), see
    // QuizLiveParticipant's class docblock.
    public function findOneForStudent(QuizLiveSession $session, User $student): ?QuizLiveParticipant
    {
        return $this->createQueryBuilder('p')
            ->where('p.session = :session')
            ->andWhere('p.student = :student')
            ->setParameter('session', $session)
            ->setParameter('student', $student)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // Ranked leaderboard for a session - highest score first, join date as a stable tiebreaker.
    /** @return list<QuizLiveParticipant> */
    public function findRankedForSession(QuizLiveSession $session): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('s')
            ->join('p.student', 's')
            ->where('p.session = :session')
            ->setParameter('session', $session)
            ->orderBy('p.score', 'DESC')
            ->addOrderBy('p.joinedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
