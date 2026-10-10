<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeacherDossierDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeacherDossierDocument>
 */
class TeacherDossierDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeacherDossierDocument::class);
    }
}
