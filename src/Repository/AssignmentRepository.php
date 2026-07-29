<?php

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Assignment>
 */
class AssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assignment::class);
    }

    /** @return list<Assignment> */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->leftJoin('a.options', 'o')
            ->where('a.program = :program')
            ->setParameter('program', $program)
            ->orderBy('a.dueDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // Student dashboard's "Travail à réaliser" card (design_handoff_dashboards etu-a): upcoming
    // items only - "pas de notion de retard côté étudiant" (§1.6), so nothing dated before $from
    // ever comes back. Audience membership is filtered by the caller via
    // AssignmentAudienceResolver::isInAudience(), same as the my-assignments list.
    /** @param list<Program> $programs @return list<Assignment> */
    public function findUpcomingForPrograms(array $programs, \DateTimeImmutable $from): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->leftJoin('a.options', 'o')
            ->where('a.program IN (:programs)')
            ->andWhere('a.dueDate >= :from')
            ->setParameter('programs', $programs)
            ->setParameter('from', $from)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
