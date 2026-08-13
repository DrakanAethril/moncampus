<?php

declare(strict_types=1);

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

    /**
     * The Programs where this student is actually tagged as following the alternance modality
     * (Modality::$isAlternance) - what makes a student an alternant, as opposed to merely being
     * enrolled in a Program that happens to run an alternance track for some of its students.
     *
     * Keyed rather than returned as a list so callers can test membership without a second loop.
     *
     * @return array<int, true> Program id => this student follows its alternance modality
     */
    public function findAlternanceProgramIdsForStudent(User $student): array
    {
        $rows = $this->createQueryBuilder('psm')
            ->select('IDENTITY(psm.program) AS programId')
            ->innerJoin('psm.modality', 'm')
            ->where('psm.student = :student')
            ->andWhere('m.isAlternance = true')
            ->setParameter('student', $student)
            ->groupBy('programId')
            ->getQuery()
            ->getResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['programId']] = true;
        }

        return $ids;
    }

    /**
     * The mirror of findAlternanceProgramIdsForStudent(): the students of one Program tagged as
     * following its alternance modality (Modality::$isAlternance).
     *
     * Keyed rather than returned as a list so callers can test membership without a second loop.
     *
     * @return array<int, true> Student id => this student follows the program's alternance modality
     */
    public function findAlternanceStudentIdsForProgram(Program $program): array
    {
        $rows = $this->createQueryBuilder('psm')
            ->select('IDENTITY(psm.student) AS studentId')
            ->innerJoin('psm.modality', 'm')
            ->where('psm.program = :program')
            ->andWhere('m.isAlternance = true')
            ->setParameter('program', $program)
            ->groupBy('studentId')
            ->getQuery()
            ->getResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['studentId']] = true;
        }

        return $ids;
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
