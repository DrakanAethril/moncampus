<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizTournament;
use App\Entity\QuizTournamentRound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizTournamentRound>
 */
class QuizTournamentRoundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizTournamentRound::class);
    }

    /**
     * The rounds of a tournament still open to joiners, the latest first - neither paired nor
     * cancelled (a round never expires by itself, §8).
     *
     * @return list<QuizTournamentRound>
     */
    public function findOpenForTournament(QuizTournament $tournament): array
    {
        /** @var list<QuizTournamentRound> $rounds */
        $rounds = $this->createQueryBuilder('r')
            ->where('r.tournament = :tournament')
            ->andWhere('r.pairedAt IS NULL AND r.cancelledAt IS NULL')
            ->setParameter('tournament', $tournament)
            ->orderBy('r.openedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rounds;
    }
}
