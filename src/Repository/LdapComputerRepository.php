<?php

namespace App\Repository;

use App\Entity\LdapComputer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LdapComputer>
 */
class LdapComputerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LdapComputer::class);
    }

    public function findOneByName(string $name): ?LdapComputer
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function countAll(?string $search = null): int
    {
        $qb = $this->createQueryBuilder('c')->select('COUNT(c.id)');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<LdapComputer> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.createdBy', 'cb')->addSelect('cb')
            ->orderBy('c.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('c.name LIKE :search OR c.dnsHostName LIKE :search OR c.operatingSystem LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }
}
