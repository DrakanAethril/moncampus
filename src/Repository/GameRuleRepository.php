<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EvaluationPeriod;
use App\Entity\GameRule;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameRule>
 */
class GameRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameRule::class);
    }

    /**
     * Every deviation this formation has saved for this period, keyed by code.
     *
     * @return array<string, GameRule>
     */
    public function findForPeriod(Program $program, EvaluationPeriod $period): array
    {
        /** @var list<GameRule> $rules */
        $rules = $this->createQueryBuilder('r')
            ->where('r.program = :program')
            ->andWhere('r.period = :period')
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->getQuery()
            ->getResult();

        $byCode = [];
        foreach ($rules as $rule) {
            $byCode[$rule->getCode()] = $rule;
        }

        return $byCode;
    }
}
