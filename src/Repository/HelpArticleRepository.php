<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HelpArticle;
use App\Entity\HelpSection;
use App\Enum\HelpArticleKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HelpArticle>
 */
class HelpArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HelpArticle::class);
    }

    /**
     * Every entry of every kind, section included.
     *
     * The help is a few hundred rows at most and each screen needs the section of every row it
     * shows (the search result's "Catégorie · Type" over-line, the home's FAQ list): one query
     * that reads the whole index beats four narrower ones that each re-join the same table.
     *
     * @return list<HelpArticle>
     */
    public function findAllWithSection(): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.section', 's')->addSelect('s')
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('a.position', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<HelpArticle> */
    public function findByKindWithSection(HelpArticleKind $kind): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.section', 's')->addSelect('s')
            ->where('a.kind = :kind')
            ->setParameter('kind', $kind)
            ->orderBy('a.position', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every language version of one article, wherever its section row sits: a translation may hang
     * off the section row of its own language or off the French one, and both resolve the same.
     *
     * @return list<HelpArticle>
     */
    public function findAllBySlugs(string $sectionSlug, string $articleSlug): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.section', 's')->addSelect('s')
            ->where('s.slug = :sectionSlug')
            ->andWhere('a.slug = :articleSlug')
            ->setParameter('sectionSlug', $sectionSlug)
            ->setParameter('articleSlug', $articleSlug)
            ->getQuery()
            ->getResult();
    }

    public function findOneBySlug(HelpSection $section, string $slug, string $locale): ?HelpArticle
    {
        return $this->findOneBy(['section' => $section, 'slug' => $slug, 'locale' => $locale]);
    }

    public function nextPosition(HelpSection $section): int
    {
        $max = $this->createQueryBuilder('a')
            ->select('MAX(a.position)')
            ->where('a.section = :section')
            ->setParameter('section', $section)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($max) ? ((int) $max) + 10 : 0;
    }
}
