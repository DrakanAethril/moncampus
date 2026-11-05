<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentDuelDraft;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizTournamentDuelDraft>
 */
class QuizTournamentDuelDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizTournamentDuelDraft::class);
    }

    /**
     * Every draft still pending in this duel, by either player, questions loaded.
     *
     * @return list<QuizTournamentDuelDraft>
     */
    public function findForDuel(QuizTournamentDuel $duel): array
    {
        /** @var list<QuizTournamentDuelDraft> $drafts */
        $drafts = $this->createQueryBuilder('d')
            ->addSelect('q')
            ->innerJoin('d.question', 'q')
            ->where('q.duel = :duel')
            ->setParameter('duel', $duel)
            ->getQuery()
            ->getResult();

        return $drafts;
    }
}
