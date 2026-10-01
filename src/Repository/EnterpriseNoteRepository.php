<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enterprise;
use App\Entity\EnterpriseNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EnterpriseNote>
 */
class EnterpriseNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnterpriseNote::class);
    }

    /** @return list<EnterpriseNote> most recent first */
    public function findFor(Enterprise $enterprise): array
    {
        return $this->findBy(['enterprise' => $enterprise], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
