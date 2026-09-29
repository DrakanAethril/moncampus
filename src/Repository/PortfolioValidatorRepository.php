<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Option;
use App\Entity\PortfolioValidator;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortfolioValidator>
 */
class PortfolioValidatorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortfolioValidator::class);
    }

    /** @return list<PortfolioValidator> the active designations of a formation, per option then name */
    public function findActiveForProgram(Program $program): array
    {
        /** @var list<PortfolioValidator> $rows */
        $rows = $this->createQueryBuilder('v')
            ->addSelect('o', 't')
            ->innerJoin('v.option', 'o')
            ->innerJoin('v.teacher', 't')
            ->where('v.program = :program')
            ->andWhere('v.inactiveDate IS NULL')
            ->setParameter('program', $program)
            ->orderBy('o.shortName', 'ASC')
            ->addOrderBy('t.lastname', 'ASC')
            ->addOrderBy('t.firstname', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<PortfolioValidator> the active designations of one teacher */
    public function findActiveForTeacher(User $teacher): array
    {
        /** @var list<PortfolioValidator> $rows */
        $rows = $this->createQueryBuilder('v')
            ->addSelect('p', 'o')
            ->innerJoin('v.program', 'p')
            ->innerJoin('v.option', 'o')
            ->where('v.teacher = :teacher')
            ->andWhere('v.inactiveDate IS NULL')
            ->setParameter('teacher', $teacher)
            ->orderBy('p.shortName', 'ASC')
            ->addOrderBy('o.shortName', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOneFor(Program $program, Option $option, User $teacher): ?PortfolioValidator
    {
        return $this->findOneBy(['program' => $program, 'option' => $option, 'teacher' => $teacher]);
    }
}
