<?php

namespace App\Repository;

use App\Entity\SchoolMailSignature;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolMailSignature>
 */
class SchoolMailSignatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolMailSignature::class);
    }

    /** No row is the normal state: it means the school's default signature applies untouched. */
    public function findOneForStudent(User $student): ?SchoolMailSignature
    {
        return $this->findOneBy(['student' => $student]);
    }
}
