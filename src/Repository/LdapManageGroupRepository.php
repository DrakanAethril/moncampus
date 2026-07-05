<?php

namespace App\Repository;

use App\Entity\LdapManageGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LdapManageGroup>
 */
class LdapManageGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LdapManageGroup::class);
    }
}
