<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentDuelAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizTournamentDuelAnswer>
 */
class QuizTournamentDuelAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizTournamentDuelAnswer::class);
    }

    /**
     * Every answer given in this duel, by either player, questions loaded.
     *
     * @return list<QuizTournamentDuelAnswer>
     */
    public function findForDuel(QuizTournamentDuel $duel): array
    {
        /** @var list<QuizTournamentDuelAnswer> $answers */
        $answers = $this->createQueryBuilder('a')
            ->addSelect('q')
            ->innerJoin('a.question', 'q')
            ->where('q.duel = :duel')
            ->setParameter('duel', $duel)
            ->getQuery()
            ->getResult();

        return $answers;
    }
}
