<?php

namespace App\Repository;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramReferentTeacherOption;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgramReferentTeacherOption>
 */
class ProgramReferentTeacherOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramReferentTeacherOption::class);
    }

    /** @return list<ProgramReferentTeacherOption> */
    public function findAllForProgramAndReferentTeacher(Program $program, User $referentTeacher): array
    {
        return $this->createQueryBuilder('prto')
            ->addSelect('o')
            ->innerJoin('prto.option', 'o')
            ->where('prto.program = :program')
            ->andWhere('prto.referentTeacher = :referentTeacher')
            ->setParameter('program', $program)
            ->setParameter('referentTeacher', $referentTeacher)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Option> */
    public function findOptionsForReferentTeacher(Program $program, User $referentTeacher): array
    {
        return array_map(
            static fn (ProgramReferentTeacherOption $link): Option => $link->getOption(),
            $this->findAllForProgramAndReferentTeacher($program, $referentTeacher),
        );
    }

    /** @return array<int, list<Option>> User id => list of Options */
    public function findOptionsByReferentTeacherForProgram(Program $program): array
    {
        $links = $this->createQueryBuilder('prto')
            ->addSelect('o')
            ->innerJoin('prto.option', 'o')
            ->where('prto.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        $optionsByReferentTeacherId = [];
        foreach ($links as $link) {
            $optionsByReferentTeacherId[$link->getReferentTeacher()->getId()][] = $link->getOption();
        }

        return $optionsByReferentTeacherId;
    }
}
