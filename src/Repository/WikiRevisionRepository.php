<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
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

    /**
     * Records the state a page is leaving behind, then prunes what is past the cap.
     *
     * Called *before* the new body is written, so a revision is what the page was, not what it
     * became - which is what makes "restore" mean something. Pruning on write rather than in a
     * scheduled command keeps the table bounded without anything to run on the server.
     */
    public function record(WikiNode $node, ?User $author): WikiRevision
    {
        $revision = new WikiRevision($node, $node->getTitle(), $node->getBody(), $author);
        $this->getEntityManager()->persist($revision);

        $this->pruneToCap($node);

        return $revision;
    }

    private function pruneToCap(WikiNode $node): void
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.id')
            ->andWhere('r.node = :node')
            ->setParameter('node', $node)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        // KEEP_PER_NODE - 1, because the revision just persisted is not in this list yet: keeping
        // 50 including it is what the cap means.
        $excess = WikiRevision::excess(array_column($rows, 'id'), WikiRevision::KEEP_PER_NODE - 1);

        if ([] === $excess) {
            return;
        }

        $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.id IN (:ids)')
            ->setParameter('ids', $excess)
            ->getQuery()
            ->execute();
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
