<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Wiki;
use App\Entity\WikiNode;
use App\Enum\WikiNodeType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WikiNode>
 */
class WikiNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WikiNode::class);
    }

    /**
     * The whole wiki in one query - the rail is assembled from this in PHP by
     * App\Service\WikiTree::assemble(). A wiki holds hundreds of nodes, not millions.
     *
     * @return list<WikiNode>
     */
    public function findLiveOf(Wiki $wiki): array
    {
        /** @var list<WikiNode> $nodes */
        $nodes = $this->createQueryBuilder('n')
            ->andWhere('n.wiki = :wiki')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('wiki', $wiki)
            ->orderBy('n.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $nodes;
    }

    /** @return list<WikiNode> */
    public function findTrashedOf(Wiki $wiki): array
    {
        /** @var list<WikiNode> $nodes */
        $nodes = $this->createQueryBuilder('n')
            ->andWhere('n.wiki = :wiki')
            ->andWhere('n.deletedAt IS NOT NULL')
            ->setParameter('wiki', $wiki)
            ->orderBy('n.deletedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $nodes;
    }

    /**
     * The slugs already taken among a node's siblings - what
     * App\Service\WikiTree::uniqueSlug() needs, since the index cannot enforce it (MySQL treats
     * every NULL parent as distinct).
     *
     * @return list<string>
     */
    public function siblingSlugs(Wiki $wiki, ?WikiNode $parent, ?WikiNode $except = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->select('n.slug')
            ->andWhere('n.wiki = :wiki')
            ->setParameter('wiki', $wiki);

        $this->restrictToParent($qb, $parent);

        if (null !== $except && null !== $except->getId()) {
            $qb->andWhere('n.id <> :except')->setParameter('except', $except->getId());
        }

        /** @var list<array{slug: string}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_column($rows, 'slug');
    }

    /** @return list<int> */
    public function siblingPositions(Wiki $wiki, ?WikiNode $parent): array
    {
        $qb = $this->createQueryBuilder('n')
            ->select('n.position')
            ->andWhere('n.wiki = :wiki')
            ->setParameter('wiki', $wiki);

        $this->restrictToParent($qb, $parent);

        /** @var list<array{position: int}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(intval(...), array_column($rows, 'position'));
    }

    /**
     * Every strict descendant of a node, found by path prefix rather than by walking parent by
     * parent - the one place the materialized path earns its keep.
     *
     * @return list<WikiNode>
     */
    public function findDescendantsOf(WikiNode $node): array
    {
        $id = $node->getId();

        if (null === $id) {
            return [];
        }

        /** @var list<WikiNode> $nodes */
        $nodes = $this->createQueryBuilder('n')
            ->andWhere('n.wiki = :wiki')
            ->andWhere('n.path LIKE :pattern')
            ->setParameter('wiki', $node->getWiki())
            ->setParameter('pattern', $node->getPath().$id.'/%')
            ->getQuery()
            ->getResult();

        return $nodes;
    }

    /**
     * The page /wiki/{id} hands over to: the first live page in reading order, folders included
     * only when they carry a body of their own.
     */
    public function findFirstReadableOf(Wiki $wiki): ?WikiNode
    {
        $nodes = $this->findLiveOf($wiki);

        usort($nodes, static fn (WikiNode $a, WikiNode $b): int => [$a->getPath(), $a->getPosition()] <=> [$b->getPath(), $b->getPosition()]);

        foreach ($nodes as $node) {
            if (WikiNodeType::Page === $node->getType() || null !== $node->getBody()) {
                return $node;
            }
        }

        return $nodes[0] ?? null;
    }

    private function restrictToParent(\Doctrine\ORM\QueryBuilder $qb, ?WikiNode $parent): void
    {
        if (null === $parent) {
            $qb->andWhere('n.parent IS NULL');

            return;
        }

        $qb->andWhere('n.parent = :parent')->setParameter('parent', $parent);
    }
}
