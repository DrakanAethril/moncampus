<?php

declare(strict_types=1);

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

    /** How many players the room holds, without loading them. */
    public function countForSession(QuizLiveSession $session): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.session = :session')
            ->setParameter('session', $session)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Adds an answer's points to its player's score in one atomic UPDATE - the answer door takes no
     * lock - and to the object in hand, so that what this request draws next is up to date.
     */
    public function addScore(QuizLiveParticipant $participant, int $points): void
    {
        if (0 === $points) {
            return;
        }
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE quiz_live_participant SET score = score + :points WHERE id = :id',
            ['points' => $points, 'id' => $participant->getId()],
        );
        // The same figure on the managed object, then marked as already written: a later flush
        // must not write it a second time.
        $participant->addScore($points);
        $uow = $this->getEntityManager()->getUnitOfWork();
        if ($uow->isInIdentityMap($participant)) {
            $uow->setOriginalEntityProperty(spl_object_id($participant), 'score', $participant->getScore());
        }
    }

    // Ranked leaderboard for a session - highest score first, join date as a stable tiebreaker. A
    // left join: a guest has no account, and an inner one would drop every guest from the board.
    /** @return list<QuizLiveParticipant> */
    public function findRankedForSession(QuizLiveSession $session): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('s')
            ->leftJoin('p.student', 's')
            ->where('p.session = :session')
            ->setParameter('session', $session)
            ->orderBy('p.score', 'DESC')
            ->addOrderBy('p.joinedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
