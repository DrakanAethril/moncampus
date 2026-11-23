<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizLiveAnswer;
use App\Entity\QuizLiveParticipant;
use App\Entity\QuizLiveSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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
     * Writes an answer in one statement, outside the ORM's unit of work: the answer door takes no
     * lock, and a second answer of the same player to the same question - or an answer arriving
     * after the question closed - must fail on the table's UNIQUE (participant, question) as a
     * plain exception, without closing the entity manager the way a failed flush would.
     *
     * @throws \Doctrine\DBAL\Exception\UniqueConstraintViolationException
     */
    public function insert(QuizLiveAnswer $answer): void
    {
        $this->getEntityManager()->getConnection()->insert('quiz_live_answer', [
            'participant_id' => $answer->getParticipant()?->getId(),
            'instance_question_id' => $answer->getInstanceQuestion()?->getId(),
            'response' => null === $answer->getResponse() ? null : json_encode($answer->getResponse(), \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR),
            'is_correct' => $answer->getIsCorrect(),
            'answered_at' => $answer->getAnsweredAt(),
            'points_awarded' => $answer->getPointsAwarded(),
        ], [
            'is_correct' => Types::BOOLEAN,
            'answered_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * The « no answer » row of every player of the room who has none for this question - one
     * statement, whatever the size of the room. INSERT IGNORE skips whoever has a row, answers
     * written by a request running at the same instant included (MySQL and MariaDB alike).
     */
    public function backfillUnanswered(QuizLiveSession $session, QuizInstanceQuestion $question, ?bool $isCorrect): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO quiz_live_answer (participant_id, instance_question_id, response, is_correct, answered_at, points_awarded)
             SELECT p.id, :question, NULL, :correct, NULL, 0 FROM quiz_live_participant p WHERE p.session_id = :session',
            ['question' => $question->getId(), 'correct' => $isCorrect, 'session' => $session->getId()],
            ['correct' => Types::BOOLEAN],
        );
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
