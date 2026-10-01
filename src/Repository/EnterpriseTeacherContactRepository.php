<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enterprise;
use App\Entity\EnterpriseTeacherContact;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EnterpriseTeacherContact>
 */
class EnterpriseTeacherContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnterpriseTeacherContact::class);
    }

    /** @return list<EnterpriseTeacherContact> */
    public function findFor(Enterprise $enterprise): array
    {
        return $this->findBy(['enterprise' => $enterprise], ['createdAt' => 'ASC']);
    }

    public function findOneFor(Enterprise $enterprise, User $teacher): ?EnterpriseTeacherContact
    {
        return $this->findOneBy(['enterprise' => $enterprise, 'teacher' => $teacher]);
    }
}
