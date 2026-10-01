<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enterprise;
use App\Entity\EnterpriseContact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EnterpriseContact>
 */
class EnterpriseContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnterpriseContact::class);
    }

    /**
     * The declared contacts of one company, those a student may read first.
     *
     * @return list<EnterpriseContact>
     */
    public function findActiveFor(Enterprise $enterprise, bool $shareableOnly = false): array
    {
        $builder = $this->createQueryBuilder('c')
            ->where('c.enterprise = :enterprise')
            ->andWhere('c.inactiveDate IS NULL')
            ->setParameter('enterprise', $enterprise)
            ->orderBy('c.shareableWithStudents', 'DESC')
            ->addOrderBy('c.name', 'ASC');

        if ($shareableOnly) {
            $builder->andWhere('c.shareableWithStudents = true');
        }

        return $builder->getQuery()->getResult();
    }
}
