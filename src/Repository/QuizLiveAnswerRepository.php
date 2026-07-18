<?php

namespace App\Repository;

use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizLiveAnswer;
use App\Entity\QuizLiveParticipant;
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

    // Powers the projector's live "N ont répondu" tally (QuizLiveSessionService::submitAnswer()'s
    // publish payload) and the Reveal screen's per-shape answer distribution.
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
}
