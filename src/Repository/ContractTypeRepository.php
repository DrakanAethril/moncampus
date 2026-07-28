<?php

namespace App\Repository;

use App\Entity\ContractType;
use App\Enum\ContractTypeCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContractType>
 */
class ContractTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractType::class);
    }

    public function findOneByCode(ContractTypeCode $code): ?ContractType
    {
        return $this->findOneBy(['code' => $code]);
    }
}
