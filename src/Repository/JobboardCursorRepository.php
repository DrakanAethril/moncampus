<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobboardCursor;
use App\Entity\Section;
use App\Enum\JobboardSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobboardCursor>
 */
class JobboardCursorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobboardCursor::class);
    }

    /** @return list<JobboardCursor> */
    public function findForSection(Section $section): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.section = :section')
            ->setParameter('section', $section)
            ->orderBy('c.source', 'ASC')
            ->addOrderBy('c.search', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByScope(Section $section, JobboardSource $source, string $search): ?JobboardCursor
    {
        return $this->findOneBy(['section' => $section, 'source' => $source, 'search' => $search]);
    }
}
