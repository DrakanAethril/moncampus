<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCourseTag;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OnlineCourseTag>
 */
class OnlineCourseTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OnlineCourseTag::class);
    }

    public function findOneByOwnerAndLabel(User $owner, string $label): ?OnlineCourseTag
    {
        return $this->findOneBy(['owner' => $owner, 'normalizedLabel' => OnlineCourseTag::normalize($label)]);
    }

    /**
     * An author's own tags matching what they are typing, with how many of their courses carry
     * each - the suggestion list of the tag field, and what the Claude connector reads to reuse a
     * word rather than coin a near-duplicate.
     *
     * @return list<array{label: string, usages: int}>
     */
    public function searchForOwner(User $owner, string $term, int $limit = 20): array
    {
        $builder = $this->getEntityManager()->createQueryBuilder()
            ->select('t.label AS label', 'COUNT(c.id) AS usages')
            ->from(OnlineCourseTag::class, 't')
            ->leftJoin(OnlineCourse::class, 'c', 'WITH', 't MEMBER OF c.tags')
            ->andWhere('t.owner = :owner')
            ->setParameter('owner', $owner)
            ->groupBy('t.id')
            ->orderBy('t.label', 'ASC')
            ->setMaxResults($limit);

        $normalized = OnlineCourseTag::normalize($term);
        if ('' !== $normalized) {
            $builder->andWhere('t.normalizedLabel LIKE :term')
                ->setParameter('term', '%'.addcslashes($normalized, '%_\\').'%');
        }

        /** @var list<array{label: string, usages: int|string}> $rows */
        $rows = $builder->getQuery()->getArrayResult();

        return array_map(static fn (array $row): array => ['label' => $row['label'], 'usages' => (int) $row['usages']], $rows);
    }

    /**
     * Removes an author's tags no course carries any more - a tag is created by typing it, and one
     * nobody uses would otherwise keep being suggested for ever.
     */
    public function deleteUnusedForOwner(User $owner): void
    {
        $this->getEntityManager()->createQuery(
            'DELETE FROM '.OnlineCourseTag::class.' t
             WHERE t.owner = :owner
               AND NOT EXISTS (SELECT 1 FROM '.OnlineCourse::class.' c WHERE t MEMBER OF c.tags)'
        )->setParameter('owner', $owner)->execute();
    }
}
