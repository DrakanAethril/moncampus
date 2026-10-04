<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EcfBooklet;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EcfBooklet>
 */
class EcfBookletRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EcfBooklet::class);
    }

    public function findOneForStudentAndTitle(User $student, string $titleCode, string $millesime): ?EcfBooklet
    {
        return $this->findOneBy(['student' => $student, 'titleCode' => $titleCode, 'millesime' => $millesime]);
    }
}
