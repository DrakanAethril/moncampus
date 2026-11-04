<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizTournament;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizTournament>
 */
class QuizTournamentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizTournament::class);
    }

    /**
     * Every tournament, opened or not, the latest to start first - the administrators' list.
     *
     * @return list<QuizTournament>
     */
    public function findAllOrdered(): array
    {
        /** @var list<QuizTournament> $tournaments */
        $tournaments = $this->createQueryBuilder('t')
            ->orderBy('t.startsAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $tournaments;
    }

    /**
     * The opened tournaments, the latest to start first - what everybody but an administrator may
     * be shown, once the voter has said who reads which.
     *
     * @return list<QuizTournament>
     */
    public function findOpened(): array
    {
        /** @var list<QuizTournament> $tournaments */
        $tournaments = $this->createQueryBuilder('t')
            ->where('t.openedAt IS NOT NULL')
            ->orderBy('t.startsAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $tournaments;
    }
}
