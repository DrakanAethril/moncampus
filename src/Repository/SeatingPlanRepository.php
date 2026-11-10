<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeatingPlan;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeatingPlan>
 */
class SeatingPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeatingPlan::class);
    }

    /** @return list<SeatingPlan> the latest modified first */
    public function findForOwner(User $owner): array
    {
        /** @var list<SeatingPlan> $plans */
        $plans = $this->createQueryBuilder('plan')
            ->addSelect('program', 'shared')
            ->join('plan.program', 'program')
            ->leftJoin('plan.sharedTeachers', 'shared')
            ->where('plan.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('plan.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $plans;
    }

    /** @return list<SeatingPlan> the plans colleagues shared with this teacher, latest modified first */
    public function findSharedWith(User $teacher): array
    {
        /** @var list<SeatingPlan> $plans */
        $plans = $this->createQueryBuilder('plan')
            ->addSelect('program', 'owner')
            ->join('plan.program', 'program')
            ->join('plan.owner', 'owner')
            ->join('plan.sharedTeachers', 'shared')
            ->where('shared = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('plan.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $plans;
    }
}
