<?php

namespace App\Repository;

use App\Entity\InternshipProgramInfo;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipProgramInfo>
 */
class InternshipProgramInfoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipProgramInfo::class);
    }

    public function findOneByProgram(Program $program): ?InternshipProgramInfo
    {
        return $this->findOneBy(['program' => $program]);
    }
}
