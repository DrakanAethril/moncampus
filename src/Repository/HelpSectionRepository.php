<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HelpSection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HelpSection>
 */
class HelpSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HelpSection::class);
    }

    /**
     * Every section with its articles already loaded.
     *
     * The help home reads each section's articles to decide whether the card leads anywhere for
     * this reader (App\Service\HelpAccess), so the join is not an optimisation here - without it
     * the page issues one query per card.
     *
     * @return list<HelpSection>
     */
    public function findAllWithArticles(): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.articles', 'a')->addSelect('a')
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('s.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneBySlug(string $slug): ?HelpSection
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function nextPosition(): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($max) ? ((int) $max) + 10 : 0;
    }
}
