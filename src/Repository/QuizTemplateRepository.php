<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizTemplate>
 */
class QuizTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizTemplate::class);
    }

    // Powers App\Controller\QuizLibraryController::list() (screen 1a) - most-recently-touched
    // first, falling back to creation date for templates never edited since. COALESCE has to be
    // selected as a HIDDEN alias rather than used inline in ORDER BY - DQL's OrderByItem grammar
    // doesn't accept a bare function call there (unlike plain SQL).
    /** @return list<QuizTemplate> */
    public function findForTeacher(User $teacher): array
    {
        return $this->createQueryBuilder('q')
            ->addSelect('COALESCE(q.lastUpdatedDate, q.creationDate) AS HIDDEN sortDate')
            ->where('q.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('sortDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The quizzes filed in one folder - what the listing draws under the sub-folders.
     *
     * By name and not by date, unlike findForTeacher(): a classement is read the way a file manager
     * is read, and « le dernier modifié en haut » is precisely what stops working once there are two
     * hundred quizzes, which is why the folders exist at all.
     *
     * @return list<QuizTemplate>
     */
    public function findInFolder(User $teacher, ?QuizFolder $folder): array
    {
        $builder = $this->createQueryBuilder('q')
            ->where('q.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('q.name', 'ASC');

        if (null === $folder) {
            $builder->andWhere('q.folder IS NULL');
        } else {
            $builder->andWhere('q.folder = :folder')->setParameter('folder', $folder);
        }

        /** @var list<QuizTemplate> $quizzes */
        $quizzes = $builder->getQuery()->getResult();

        return $quizzes;
    }

    /**
     * A name search over the whole library, folders ignored - the way back to a quiz whose folder
     * the teacher has forgotten.
     *
     * @return list<QuizTemplate>
     */
    public function searchByName(User $teacher, string $terms): array
    {
        /** @var list<QuizTemplate> $quizzes */
        $quizzes = $this->createQueryBuilder('q')
            ->where('q.teacher = :teacher')
            ->andWhere('q.name LIKE :terms')
            ->setParameter('teacher', $teacher)
            ->setParameter('terms', '%'.addcslashes($terms, '%_').'%')
            ->orderBy('q.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $quizzes;
    }

    /**
     * How many quizzes a folder holds, at any depth - the folder row's own count.
     *
     * Read off the descendants' materialized path rather than by walking the tree: one query for a
     * whole branch, the same reading App\Repository\FileLibraryNodeRepository::countFilesUnder()
     * does.
     */
    public function countInSubtree(QuizFolder $folder): int
    {
        $count = $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->join('q.folder', 'f')
            ->where('q.teacher = :teacher')
            ->andWhere('f.owner = :owner')
            ->andWhere('f.id = :id OR f.path LIKE :pattern')
            ->setParameter('teacher', $folder->getOwner())
            ->setParameter('owner', $folder->getOwner())
            ->setParameter('id', $folder->getId())
            ->setParameter('pattern', $folder->childPath().'%')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
