<?php

namespace App\Repository;

use App\Entity\SeancePhaseTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeancePhaseTemplate>
 */
class SeancePhaseTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeancePhaseTemplate::class);
    }
}
