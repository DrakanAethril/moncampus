<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConsoleSnippet;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConsoleSnippet>
 */
class ConsoleSnippetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsoleSnippet::class);
    }

    /**
     * What one person may see in their palette: their own, and what colleagues have shared.
     *
     * Ordered by use rather than by name, which is the difference between a palette and a list -
     * the command somebody runs twelve times a week has to be the first one under the cursor.
     *
     * @return list<ConsoleSnippet>
     */
    public function findVisibleTo(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.owner = :user OR s.shared = true')
            ->setParameter('user', $user)
            ->orderBy('s.useCount', 'DESC')
            ->addOrderBy('s.position', 'ASC')
            ->addOrderBy('s.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<ConsoleSnippet> */
    public function findOwnedBy(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('s.label', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
