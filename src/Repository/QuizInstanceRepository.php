<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\QuizInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizInstance>
 */
class QuizInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizInstance::class);
    }

    // Powers App\Controller\ProgramQuizController::list() - most recently launched first.
    /** @return list<QuizInstance> */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.program = :program')
            ->setParameter('program', $program)
            ->orderBy('i.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // Feeds App\Twig\StructureNavigationExtension's per-request "does this Program have a Quiz
    // nav entry" cache - a single DISTINCT query covering every Program at once (not one COUNT
    // per Program row) since the nav renders on every authenticated page for every visible
    // Program.
    /** @return list<int> */
    public function findProgramIdsWithInstances(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('DISTINCT IDENTITY(i.program) AS programId')
            ->getQuery()
            ->getScalarResult();

        return array_map(intval(...), array_column($rows, 'programId'));
    }
}
