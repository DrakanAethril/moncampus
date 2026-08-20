<?php

declare(strict_types=1);

namespace App\Repository;

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
}
