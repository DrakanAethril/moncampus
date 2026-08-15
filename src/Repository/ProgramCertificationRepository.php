<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgramCertification>
 */
class ProgramCertificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramCertification::class);
    }

    /** @return list<ProgramCertification> */
    public function findForProgram(Program $program): array
    {
        /** @var list<ProgramCertification> $rows */
        $rows = $this->createQueryBuilder('c')
            ->where('c.program = :program')
            ->setParameter('program', $program)
            ->orderBy('c.label', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The certification that applies to a student on this Option: the option-specific row when
     * there is one, otherwise the Program-wide row (null option). Returns null when the Program
     * declares no certification at all.
     */
    public function findForOption(Program $program, ?Option $option): ?ProgramCertification
    {
        $fallback = null;

        foreach ($this->findForProgram($program) as $certification) {
            if (null !== $option && $certification->getOption() === $option) {
                return $certification;
            }

            if (null === $certification->getOption()) {
                $fallback = $certification;
            }
        }

        return $fallback;
    }

    public function findOneForProgramAndOption(Program $program, ?Option $option): ?ProgramCertification
    {
        $builder = $this->createQueryBuilder('c')
            ->where('c.program = :program')
            ->setParameter('program', $program);

        if (null === $option) {
            $builder->andWhere('c.option IS NULL');
        } else {
            $builder->andWhere('c.option = :option')->setParameter('option', $option);
        }

        return $builder->getQuery()->getOneOrNullResult();
    }
}
