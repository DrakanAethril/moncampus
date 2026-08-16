<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeletedObject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeletedObject>
 */
class DeletedObjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeletedObject::class);
    }

    /**
     * What the nightly purge has to remove: everything still holding bytes whose retention window
     * has run out, oldest first, bounded by `--limit`.
     *
     * The window differs by origin (design/validated/object-deletion.md), so the caller passes a
     * cut-off date per origin rather than one for the lot - which is also why this takes a map and
     * not a single date: two queries would read the table twice for one pass.
     *
     * @param array<string, \DateTimeImmutable> $cutoffByOrigin origin => "deleted before this"
     * @param \DateTimeImmutable                $defaultCutoff  for every origin not named above
     *
     * @return list<DeletedObject>
     */
    public function findDue(array $cutoffByOrigin, \DateTimeImmutable $defaultCutoff, int $limit): array
    {
        $builder = $this->createQueryBuilder('o')
            ->where('o.purgedAt IS NULL')
            ->orderBy('o.deletedAt', 'ASC')
            ->setMaxResults($limit);

        $conditions = ['(o.origin NOT IN (:namedOrigins) AND o.deletedAt < :defaultCutoff)'];
        $builder->setParameter('namedOrigins', [] === $cutoffByOrigin ? [''] : array_keys($cutoffByOrigin));
        $builder->setParameter('defaultCutoff', $defaultCutoff);

        $index = 0;

        foreach ($cutoffByOrigin as $origin => $cutoff) {
            $conditions[] = \sprintf('(o.origin = :origin%1$d AND o.deletedAt < :cutoff%1$d)', $index);
            $builder->setParameter(\sprintf('origin%d', $index), $origin);
            $builder->setParameter(\sprintf('cutoff%d', $index), $cutoff);
            ++$index;
        }

        $builder->andWhere('('.implode(' OR ', $conditions).')');

        /** @var list<DeletedObject> $due */
        $due = $builder->getQuery()->getResult();

        return $due;
    }

    /**
     * Everything still recoverable among these keys, for a corbeille that has to say how long is
     * left on each line.
     *
     * @return array<string, DeletedObject> keyed by storage key
     */
    public function findPendingByKeys(string ...$storageKeys): array
    {
        if ([] === $storageKeys) {
            return [];
        }

        /** @var list<DeletedObject> $pending */
        $pending = $this->createQueryBuilder('o')
            ->where('o.purgedAt IS NULL')
            ->andWhere('o.storageKey IN (:keys)')
            ->setParameter('keys', $storageKeys)
            ->getQuery()
            ->getResult();

        $byKey = [];

        foreach ($pending as $row) {
            $byKey[$row->getStorageKey()] = $row;
        }

        return $byKey;
    }
}
