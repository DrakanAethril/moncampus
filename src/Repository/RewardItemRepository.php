<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\RewardItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RewardItem>
 */
class RewardItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RewardItem::class);
    }

    /**
     * A formation's catalogue: what it created, plus the establishment-wide entries every formation
     * has (the four tiers).
     *
     * **The order is a CASE in a HIDDEN alias, never `ORDER BY r.nature`**: sorting on an enum column
     * sorts the stored values, so `offline` would come before `symbolic` - the trap this repository
     * already paid for once, on the quiz library's folders.
     *
     * @return list<RewardItem>
     */
    public function catalogueFor(Program $program, bool $activeOnly = true): array
    {
        $query = $this->createQueryBuilder('r')
            ->addSelect("CASE r.nature WHEN 'consumable' THEN 1 WHEN 'symbolic' THEN 2 ELSE 3 END AS HIDDEN nature_rank")
            ->where('r.program = :program OR r.program IS NULL')
            ->orderBy('nature_rank', 'ASC')
            ->addOrderBy('r.label', 'ASC')
            ->setParameter('program', $program);

        if ($activeOnly) {
            $query->andWhere('r.active = true');
        }

        /** @var list<RewardItem> $items */
        $items = $query->getQuery()->getResult();

        return $items;
    }

    /**
     * The six level frames, lowest first - what a closure grants as a student's total opens them.
     *
     * @return list<RewardItem>
     */
    public function levelFrames(): array
    {
        /** @var list<RewardItem> $items */
        $items = $this->createQueryBuilder('r')
            ->where('r.program IS NULL')
            ->andWhere('r.active = true')
            ->andWhere('r.level IS NOT NULL')
            ->orderBy('r.level', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function findTier(string $tierCode): ?RewardItem
    {
        return $this->findOneBy(['tierCode' => $tierCode, 'program' => null]);
    }
}
