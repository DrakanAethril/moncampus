<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WikiNode;
use App\Entity\WikiRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WikiRevision>
 */
class WikiRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WikiRevision::class);
    }

    /** @return list<WikiRevision> */
    public function findForNode(WikiNode $node): array
    {
        /** @var list<WikiRevision> $revisions */
        $revisions = $this->createQueryBuilder('r')
            ->addSelect('a')
            ->leftJoin('r.author', 'a')
            ->andWhere('r.node = :node')
            ->setParameter('node', $node)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $revisions;
    }
}
