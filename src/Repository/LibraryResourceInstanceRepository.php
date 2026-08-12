<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LibraryResourceInstance;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LibraryResourceInstance>
 */
class LibraryResourceInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryResourceInstance::class);
    }

    /**
     * Every resource of a Program, wherever it hangs - what the access condition form offers as
     * "une fois telle ressource consultée", and the corrigé libéré au dépôt is the use that
     * justifies the whole feature.
     *
     * The three levels are asked for in one query rather than three: a resource sits on exactly one
     * of them, so the union is the list, and a phase's resource is reached through its séance -
     * phases are never shown to students but their handouts are.
     *
     * @return list<LibraryResourceInstance>
     */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.sequenceInstance', 'q')
            ->leftJoin('r.seanceInstance', 's')
            ->leftJoin('r.seancePhaseInstance', 'p')
            ->leftJoin('p.seanceInstance', 'ps')
            ->where('q.program = :program')
            ->orWhere('s.program = :program')
            ->orWhere('ps.program = :program')
            ->setParameter('program', $program)
            ->orderBy('r.label', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
