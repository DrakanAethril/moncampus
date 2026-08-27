<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\RewardGrant;
use App\Entity\RewardItem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RewardGrant>
 */
class RewardGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RewardGrant::class);
    }

    /**
     * One student's shelf, most recent first - every period, not only the current one: a symbolic
     * reward is acquired for good and does not stop existing when its term ends.
     *
     * @return list<RewardGrant>
     */
    public function shelfFor(User $student): array
    {
        /** @var list<RewardGrant> $grants */
        $grants = $this->createQueryBuilder('g')
            ->addSelect('i')
            ->join('g.item', 'i')
            ->where('g.student = :student')
            ->orderBy('g.grantedAt', 'DESC')
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return $grants;
    }

    /**
     * Everything granted to one class, most recent first - the « Attribuées » tab.
     *
     * No period filter: a reward granted for a mock exam or an open day carries none, and a list
     * that filtered on one would simply never show it.
     *
     * @return list<RewardGrant>
     */
    public function grantedIn(Program $program): array
    {
        /** @var list<RewardGrant> $grants */
        $grants = $this->createQueryBuilder('g')
            ->addSelect('i')
            ->join('g.item', 'i')
            ->where('g.program = :program')
            ->orderBy('g.grantedAt', 'DESC')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        return $grants;
    }

    /** How many of an entry are in circulation, all periods together - the catalogue's counter. */
    public function countGranted(RewardItem $item): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->where('g.item = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Whether this student already holds this entry - what makes a closure idempotent.
     *
     * Not scoped to a period, and not to a formation either: a level frame is held for good, and a
     * student who reached level 4 last year does not collect it again on arriving somewhere else.
     */
    public function alreadyHolds(RewardItem $item, User $student): bool
    {
        return null !== $this->createQueryBuilder('g')
            ->select('g.id')
            ->where('g.item = :item')
            ->andWhere('g.student = :student')
            ->setParameter('item', $item)
            ->setParameter('student', $student)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The consumables this student still holds unspent - what the shelf offers a « Utiliser » on.
     *
     * @return list<RewardGrant>
     */
    public function unusedConsumablesFor(User $student): array
    {
        /** @var list<RewardGrant> $grants */
        $grants = $this->createQueryBuilder('g')
            ->join('g.item', 'i')
            ->where('g.student = :student')
            ->andWhere('g.usedAt IS NULL')
            ->andWhere("i.nature = 'consumable'")
            ->orderBy('g.grantedAt', 'ASC')
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return $grants;
    }
}
