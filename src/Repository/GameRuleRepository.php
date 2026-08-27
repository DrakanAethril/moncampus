<?php

declare(strict_types=1);

namespace App\Repository;

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
     * Every deviation this formation has saved, keyed by code.
     *
     * @return array<string, GameRule>
     */
    public function findForProgram(Program $program): array
    {
        /** @var list<GameRule> $rules */
        $rules = $this->createQueryBuilder('r')
            ->where('r.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        $byCode = [];
        foreach ($rules as $rule) {
            $byCode[$rule->getCode()] = $rule;
        }

        return $byCode;
    }
}
