<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameAlias;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameAlias>
 */
class GameAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameAlias::class);
    }

    public function findOneFor(User $student, Program $program): ?GameAlias
    {
        return $this->findOneBy(['student' => $student, 'program' => $program]);
    }

    /**
     * Every alias of one class and period, keyed by student id - what the ranking is drawn from.
     *
     * @return array<int, GameAlias>
     */
    public function findForProgram(Program $program): array
    {
        /** @var list<GameAlias> $rows */
        $rows = $this->createQueryBuilder('a')
            ->addSelect('f')
            ->leftJoin('a.figure', 'f')
            ->where('a.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        $byStudent = [];
        foreach ($rows as $row) {
            $byStudent[(int) $row->getStudent()->getId()] = $row;
        }

        return $byStudent;
    }

    /**
     * The aliases still waiting for a choice past their deadline - what J+7 attributes by default.
     *
     * @return list<GameAlias>
     */
    public function findLapsed(\DateTimeImmutable $before): array
    {
        /** @var list<GameAlias> $rows */
        $rows = $this->createQueryBuilder('a')
            ->where('a.figure IS NULL')
            ->andWhere('a.offeredAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
