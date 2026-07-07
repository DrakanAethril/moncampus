<?php

namespace App\Repository;

use App\Entity\InternshipTutorEvaluationSkill;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipTutorEvaluationSkill>
 *
 * No standalone queries - rows are only ever read/written through their parent
 * InternshipTutorEvaluation.
 */
class InternshipTutorEvaluationSkillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipTutorEvaluationSkill::class);
    }
}
