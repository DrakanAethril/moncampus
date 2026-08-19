<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Progression;
use App\Entity\SchoolYear;
use App\Entity\Topic;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Progression>
 */
class ProgressionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Progression::class);
    }

    public function findOneForTopic(Topic $topic): ?Progression
    {
        return $this->findOneBy(['topic' => $topic]);
    }

    /**
     * The rows of screen 3a, sorted the way the design asks (classes A→Z, then matières A→Z).
     * Fetch-joins the whole read path - the list shows an hour volume and D/F/S counters, so every
     * row would otherwise trigger a topic + program + cohort + evaluations lookup.
     *
     * Owner OR co-animator, deliberately: a co-animated progression *appears* in both teachers'
     * own lists rather than merely being reachable by both. Without that, the co-animator holds
     * ProgressionVoter::EDIT on a screen they have no link to - the half-shipped feature this
     * design names explicitly, and the list every other lot is reached through.
     *
     * @return list<Progression>
     */
    public function findForTeacher(User $teacher, ?SchoolYear $schoolYear = null): array
    {
        $builder = $this->createQueryBuilder('p')
            ->addSelect('t', 'pr', 'c', 'e')
            ->innerJoin('p.topic', 't')
            ->innerJoin('t.program', 'pr')
            ->innerJoin('pr.cohort', 'c')
            ->leftJoin('t.evaluations', 'e')
            ->leftJoin('p.coTeachers', 'ct')
            ->where('p.teacher = :teacher OR ct = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('t.name', 'ASC');

        if (null !== $schoolYear) {
            $builder->andWhere('pr.schoolYear = :schoolYear')->setParameter('schoolYear', $schoolYear);
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * Everything the annual/month calendars (4a/4b) need in one query: the progressions of this
     * teacher for a school year, down to their placements' créneaux.
     *
     * Widened to co-animators for the same reason as findForTeacher() above - a plan a teacher may
     * edit but never sees in their own calendar is a plan they will not keep.
     *
     * @return list<Progression>
     */
    public function findForTeacherWithPlacements(User $teacher, SchoolYear $schoolYear): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('t', 'pr', 'c', 's', 'si', 'se', 'pl', 'ls')
            ->innerJoin('p.topic', 't')
            ->innerJoin('t.program', 'pr')
            ->innerJoin('pr.cohort', 'c')
            ->leftJoin('p.sequences', 's')
            ->leftJoin('s.sequenceInstance', 'si')
            ->leftJoin('s.seances', 'se')
            ->leftJoin('se.placements', 'pl')
            ->leftJoin('pl.lessonSession', 'ls')
            ->leftJoin('p.coTeachers', 'ct')
            ->where('p.teacher = :teacher OR ct = :teacher')
            ->andWhere('pr.schoolYear = :schoolYear')
            ->setParameter('teacher', $teacher)
            ->setParameter('schoolYear', $schoolYear)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
