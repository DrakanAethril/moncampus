<?php

namespace App\Repository;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramTeacherOption;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgramTeacherOption>
 */
class ProgramTeacherOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramTeacherOption::class);
    }

    /** @return list<ProgramTeacherOption> */
    public function findAllForProgramAndTeacher(Program $program, User $teacher): array
    {
        return $this->createQueryBuilder('pto')
            ->addSelect('o')
            ->innerJoin('pto.option', 'o')
            ->where('pto.program = :program')
            ->andWhere('pto.teacher = :teacher')
            ->setParameter('program', $program)
            ->setParameter('teacher', $teacher)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Option> */
    public function findOptionsForTeacher(Program $program, User $teacher): array
    {
        return array_map(
            static fn (ProgramTeacherOption $link): Option => $link->getOption(),
            $this->findAllForProgramAndTeacher($program, $teacher),
        );
    }

    /** @return array<int, list<Option>> User id => list of Options */
    public function findOptionsByTeacherForProgram(Program $program): array
    {
        $links = $this->createQueryBuilder('pto')
            ->addSelect('o')
            ->innerJoin('pto.option', 'o')
            ->where('pto.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        $optionsByTeacherId = [];
        foreach ($links as $link) {
            $optionsByTeacherId[$link->getTeacher()->getId()][] = $link->getOption();
        }

        return $optionsByTeacherId;
    }
}
