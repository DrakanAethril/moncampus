<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGameAvatar;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameAvatar>
 */
class SeriousGameAvatarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameAvatar::class);
    }
}
