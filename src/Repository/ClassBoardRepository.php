<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassBoard;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClassBoard>
 */
class ClassBoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassBoard::class);
    }

    /**
     * One person's boards, the last one worked on first - the list is where a teacher picks up the
     * board they prepared yesterday.
     *
     * @return list<ClassBoard>
     */
    public function findForOwner(User $owner): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.program', 'p')
            ->addSelect('p')
            ->andWhere('b.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('b.updatedAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> */
    public function findNamesForOwner(User $owner): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('b.name')
            ->andWhere('b.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'name');
    }
}
