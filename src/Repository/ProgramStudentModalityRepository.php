<?php

namespace App\Repository;

use App\Entity\Modality;
use App\Entity\Program;
use App\Entity\ProgramStudentModality;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgramStudentModality>
 */
class ProgramStudentModalityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramStudentModality::class);
    }

    /** @return list<ProgramStudentModality> */
    public function findAllForProgramAndStudent(Program $program, User $student): array
    {
        return $this->createQueryBuilder('psm')
            ->addSelect('m')
            ->innerJoin('psm.modality', 'm')
            ->where('psm.program = :program')
            ->andWhere('psm.student = :student')
            ->setParameter('program', $program)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Modality> */
    public function findModalitiesForStudent(Program $program, User $student): array
    {
        return array_map(
            static fn (ProgramStudentModality $link): Modality => $link->getModality(),
            $this->findAllForProgramAndStudent($program, $student),
        );
    }

    /** @return array<int, list<Modality>> User id => list of Modalities */
    public function findModalitiesByStudentForProgram(Program $program): array
    {
        $links = $this->createQueryBuilder('psm')
            ->addSelect('m')
            ->innerJoin('psm.modality', 'm')
            ->where('psm.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        $modalitiesByStudentId = [];
        foreach ($links as $link) {
            $modalitiesByStudentId[$link->getStudent()->getId()][] = $link->getModality();
        }

        return $modalitiesByStudentId;
    }
}
