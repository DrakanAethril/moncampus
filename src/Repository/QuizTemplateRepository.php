<?php

namespace App\Repository;

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
}
