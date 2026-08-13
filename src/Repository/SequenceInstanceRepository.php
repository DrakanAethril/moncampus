<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\SequenceInstance;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SequenceInstance>
 */
class SequenceInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SequenceInstance::class);
    }

    /** @return list<SequenceInstance> */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.program = :program')
            ->setParameter('program', $program)
            ->orderBy('s.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The same list narrowed to one teacher's own instantiations.
     *
     * A SequenceInstance hangs off a Program, not off a Topic, so a whole class's séquences are one
     * pool shared by every teacher of that class. The progression module never plans out of that
     * pool: a progression is one teacher's own planning of their own matière (ProgressionVoter), so
     * what it offers - and what it lets delete - is that teacher's own copies.
     *
     * @return list<SequenceInstance>
     */
    public function findForProgramCreatedBy(Program $program, User $creator): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.program = :program')
            ->andWhere('s.createdBy = :creator')
            ->setParameter('program', $program)
            ->setParameter('creator', $creator)
            ->orderBy('s.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
