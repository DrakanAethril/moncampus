<?php

namespace App\Repository;

use App\Entity\Enterprise;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Enterprise>
 */
class EnterpriseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Enterprise::class);
    }

    // Powers InternshipTutorLinkType's "pick an existing enterprise" dropdown.
    /** @return list<Enterprise> */
    public function findAllActiveOrderedByName(?User $viewer = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.inactiveDate IS NULL')
            ->orderBy('e.name', 'ASC');

        // Same asymmetry as ProgramRepository::findAlternanceForSchoolYear(): a test account is
        // confined to test employers, a real one keeps seeing every one of them - somebody has to
        // be able to set a test alternance up in the first place. $viewer is optional so a caller
        // with no user in hand keeps the unfiltered list rather than silently getting one view.
        if ($viewer?->isTestUser()) {
            $qb->andWhere('e.testEnterprise = true');
        }

        return $qb->getQuery()->getResult();
    }
}
