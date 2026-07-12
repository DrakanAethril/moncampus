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
    // Deduplicates in PHP: a student holding several of the given options would otherwise appear
    // once per matching link row.
    /**
     * @param iterable<Option> $options
     *
     * @return list<User>
     */
    public function findStudentsForProgramAndOptions(Program $program, iterable $options): array
    {
        $options = $options instanceof \Traversable ? iterator_to_array($options) : $options;
        if ([] === $options) {
            return [];
        }

        $links = $this->createQueryBuilder('pso')
            ->addSelect('u')
            ->innerJoin('pso.student', 'u')
            ->where('pso.program = :program')
            ->andWhere('pso.option IN (:options)')
            ->setParameter('program', $program)
            ->setParameter('options', $options)
            ->getQuery()
            ->getResult();

        $studentsById = [];
        foreach ($links as $link) {
            $student = $link->getStudent();
            $studentsById[$student->getId()] = $student;
        }

        return array_values($studentsById);
    }
}
