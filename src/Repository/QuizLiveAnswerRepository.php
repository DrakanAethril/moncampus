<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizLiveAnswer;
use App\Entity\QuizLiveParticipant;
use App\Entity\QuizLiveSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizLiveAnswer>
 */
class QuizLiveAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizLiveAnswer::class);
    }

    public function findOneFor(QuizLiveParticipant $participant, QuizInstanceQuestion $question): ?QuizLiveAnswer
    {
        return $this->createQueryBuilder('a')
            ->where('a.participant = :participant')
            ->andWhere('a.instanceQuestion = :question')
            ->setParameter('participant', $participant)
            ->setParameter('question', $question)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // Powers the Reveal screen's per-shape answer distribution.
    /** @return list<QuizLiveAnswer> */
    public function findForQuestion(QuizInstanceQuestion $question): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.participant', 'p')
            ->where('a.instanceQuestion = :question')
            ->setParameter('question', $question)
            ->getQuery()
            ->getResult();
    }

    /**
     * How many players have answered this question - a backfilled « no answer » row (null response)
     * is not an answer. One COUNT: the answer door runs it under the session's lock, where walking
     * every participant's collection cost a query per player.
     */
    public function countAnswered(QuizInstanceQuestion $question): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.instanceQuestion = :question')
            ->andWhere('a.response IS NOT NULL')
            ->setParameter('question', $question)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The participants who already have a row for this question, answered or backfilled - what
     * closing a question needs to know whom to backfill, in one query.
     *
     * @return array<int, true> keyed by participant id
     */
    public function participantIdsWithRow(QuizInstanceQuestion $question): array
    {
        /** @var list<array{id: int|string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.participant) AS id')
            ->where('a.instanceQuestion = :question')
            ->setParameter('question', $question)
            ->getQuery()
            ->getScalarResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['id']] = true;
        }

        return $ids;
    }

    // The whole contest at once, for the analysis of a finished one (LiveQuestionAnalysis).
    /** @return list<QuizLiveAnswer> */
    public function findForSession(QuizLiveSession $session): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.participant', 'p')
            ->where('p.session = :session')
            ->setParameter('session', $session)
            ->getQuery()
            ->getResult();
    }
}
