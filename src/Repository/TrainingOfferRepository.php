<?php

namespace App\Repository;

use App\Entity\TrainingOffer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingOffer>
 */
class TrainingOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingOffer::class);
    }

    /** @return list<TrainingOffer> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('v', 'g')
            ->leftJoin('o.validators', 'v')
            ->leftJoin('o.visibilityGroups', 'g')
            ->orderBy('o.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The offers a student may apply to: at least one group in common (design_handoff_workflow_
     * postulation, tour F). Group membership is read off the roles the directory grants, which is
     * what App\Entity\Group::$role holds.
     *
     * @return list<TrainingOffer>
     */
    public function findVisibleForStudent(User $student): array
    {
        $roles = $student->getRoles();

        foreach ($student->getManualGroups() as $group) {
            $roles[] = $group->getRole();
        }

        if ([] === $roles) {
            return [];
        }

        return $this->createQueryBuilder('o')
            ->addSelect('v', 'g')
            ->leftJoin('o.validators', 'v')
            ->join('o.visibilityGroups', 'g')
            ->andWhere('g.role IN (:roles)')
            ->setParameter('roles', array_values(array_unique($roles)))
            ->orderBy('o.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Is this teacher a validator on anything at all? Screen 8c hangs on the answer. */
    public function isValidator(User $user): bool
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->join('o.validators', 'v')
            ->andWhere('v = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function countValidatedOffersFor(User $user): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->join('o.validators', 'v')
            ->andWhere('v = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
