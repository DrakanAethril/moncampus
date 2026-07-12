<?php

namespace App\Repository;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramStudentOption;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgramStudentOption>
 */
class ProgramStudentOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramStudentOption::class);
    }

    /** @return list<ProgramStudentOption> */
    public function findAllForProgramAndStudent(Program $program, User $student): array
    {
        return $this->createQueryBuilder('pso')
            ->addSelect('o')
            ->innerJoin('pso.option', 'o')
            ->where('pso.program = :program')
            ->andWhere('pso.student = :student')
            ->setParameter('program', $program)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Option> */
    public function findOptionsForStudent(Program $program, User $student): array
    {
        return array_map(
            static fn (ProgramStudentOption $link): Option => $link->getOption(),
            $this->findAllForProgramAndStudent($program, $student),
        );
    }

    /** @return array<int, list<Option>> User id => list of Options */
    public function findOptionsByStudentForProgram(Program $program): array
    {
        $links = $this->createQueryBuilder('pso')
            ->addSelect('o')
            ->innerJoin('pso.option', 'o')
            ->where('pso.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        $optionsByStudentId = [];
        foreach ($links as $link) {
            $optionsByStudentId[$link->getStudent()->getId()][] = $link->getOption();
        }

        return $optionsByStudentId;
    }

    // Powers Assignment's option-scoped audience (see App\Service\AssignmentAudienceResolver).
    // No deduplication needed - the (program_id, student_id, option_id) unique constraint on
    // ProgramStudentOption already guarantees at most one link row per student here.
    /** @return list<User> */
    public function findStudentsForProgramAndOption(Program $program, Option $option): array
    {
        $links = $this->createQueryBuilder('pso')
            ->addSelect('u')
            ->innerJoin('pso.student', 'u')
            ->where('pso.program = :program')
            ->andWhere('pso.option = :option')
            ->setParameter('program', $program)
            ->setParameter('option', $option)
            ->getQuery()
            ->getResult();

        return array_map(static fn (ProgramStudentOption $link): User => $link->getStudent(), $links);
    }
}
