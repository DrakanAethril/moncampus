<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CompanySearchCategory;
use App\Enum\CompanyCategoryTheme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanySearchCategory>
 */
class CompanySearchCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanySearchCategory::class);
    }

    /**
     * Every category, in the order the screen draws them: by theme as the enum lists them, then by
     * position inside a theme.
     *
     * @return list<CompanySearchCategory>
     */
    public function findOrdered(): array
    {
        /** @var list<CompanySearchCategory> $categories */
        $categories = $this->createQueryBuilder('c')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        $themeOrder = array_flip(array_map(static fn (CompanyCategoryTheme $theme): string => $theme->value, CompanyCategoryTheme::cases()));
        usort($categories, static fn (CompanySearchCategory $a, CompanySearchCategory $b): int => [$themeOrder[$a->getTheme()->value], $a->getPosition(), $a->getId()]
            <=> [$themeOrder[$b->getTheme()->value], $b->getPosition(), $b->getId()]);

        return $categories;
    }

    /**
     * The categories under their theme, the themes with nothing in them left out.
     *
     * @return list<array{theme: CompanyCategoryTheme, categories: list<CompanySearchCategory>}>
     */
    public function findGroupedByTheme(): array
    {
        $groups = [];
        foreach ($this->findOrdered() as $category) {
            $groups[$category->getTheme()->value] ??= ['theme' => $category->getTheme(), 'categories' => []];
            $groups[$category->getTheme()->value]['categories'][] = $category;
        }

        return array_values($groups);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<CompanySearchCategory>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_values(array_filter(
            $this->findOrdered(),
            static fn (CompanySearchCategory $category): bool => \in_array($category->getId(), $ids, true),
        ));
    }

    public function nextPosition(CompanyCategoryTheme $theme): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->where('c.theme = :theme')
            ->setParameter('theme', $theme)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($max) ? (int) $max + 1 : 0;
    }
}
