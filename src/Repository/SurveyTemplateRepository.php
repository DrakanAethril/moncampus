<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SurveyFolder;
use App\Entity\SurveyTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyTemplate>
 */
class SurveyTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyTemplate::class);
    }

    /**
     * Most-recently-touched first, falling back to the creation date for models never edited
     * since - the very ordering QuizTemplateRepository::findForTeacher() uses, COALESCE selected
     * as a HIDDEN alias because DQL's ORDER BY grammar refuses a bare function call.
     *
     * @return list<SurveyTemplate>
     */
    public function findForOwner(User $owner): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('COALESCE(t.lastUpdatedDate, t.creationDate) AS HIDDEN sortDate')
            ->where('t.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('sortDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countForOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The models filed in one folder - what the listing draws under the sub-folders.
     *
     * By name and not by date, unlike findForOwner(): a classement is read the way a file manager
     * is read, and « le dernier modifié en haut » is precisely what stops working once the library
     * is deep enough to need folders at all.
     *
     * @return list<SurveyTemplate>
     */
    public function findInFolder(User $owner, ?SurveyFolder $folder): array
    {
        $builder = $this->createQueryBuilder('t')
            ->where('t.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('t.name', 'ASC');

        if (null === $folder) {
            $builder->andWhere('t.folder IS NULL');
        } else {
            $builder->andWhere('t.folder = :folder')->setParameter('folder', $folder);
        }

        /** @var list<SurveyTemplate> $templates */
        $templates = $builder->getQuery()->getResult();

        return $templates;
    }

    /**
     * A name search over the whole library, folders ignored - the way back to a model whose folder
     * the author has forgotten.
     *
     * @return list<SurveyTemplate>
     */
    public function searchByName(User $owner, string $terms): array
    {
        /** @var list<SurveyTemplate> $templates */
        $templates = $this->createQueryBuilder('t')
            ->where('t.owner = :owner')
            ->andWhere('t.name LIKE :terms')
            ->setParameter('owner', $owner)
            ->setParameter('terms', '%'.addcslashes($terms, '%_').'%')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $templates;
    }

    /**
     * How many models a folder holds, at any depth - the folder row's own count.
     *
     * Read off the descendants' materialized path rather than by walking the tree: one query for a
     * whole branch.
     */
    public function countInSubtree(SurveyFolder $folder): int
    {
        $count = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.folder', 'f')
            ->where('t.owner = :owner')
            ->andWhere('f.owner = :owner')
            ->andWhere('f.id = :id OR f.path LIKE :pattern')
            ->setParameter('owner', $folder->getOwner())
            ->setParameter('id', $folder->getId())
            ->setParameter('pattern', $folder->childPath().'%')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
