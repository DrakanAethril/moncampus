<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CrmDossierPiece;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmDossierPiece>
 */
class CrmDossierPieceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmDossierPiece::class);
    }
}
