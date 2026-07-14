<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\Topic;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Topic>
 */
class TopicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Topic::class);
    }

    // Powers the Topic dropdown on the lesson session form - only that program's own active
    // topics are valid choices.
    /** @return list<Topic> */
    public function findAllActiveForProgram(Program $program): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.program = :program')
            ->andWhere('t.inactiveDate IS NULL')
            ->setParameter('program', $program)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Powers the Tom Select ajax search on the Matière field of the lesson session form - see
    // App\Controller\ProgramTimetableSettingsController::topicsSearch().
    /** @return list<Topic> */
    public function searchActiveForProgram(Program $program, ?string $search, int $limit): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.program = :program')
            ->andWhere('t.inactiveDate IS NULL')
            ->setParameter('program', $program)
            ->orderBy('t.name', 'ASC')
            ->setMaxResults($limit);

        if (null !== $search && '' !== $search) {
            $qb->andWhere('t.name LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return $qb->getQuery()->getResult();
    }

    // Powers App\Controller\ProgramSyllabusController - an always-active-only, unpaged full-table
    // display (a Program's own topic list is small), with DataTables/RowGroup doing the actual
    // grouping/sorting/hour-total calculation client-side, so this just needs a sensible initial
    // order (matching what the client-side sort will produce anyway) and topicGroup/
    // topicGroup.options eager-loaded to avoid an N+1 per row - the syllabus page's per-Option
    // hour totals need every topic's group's Option scoping.
    /** @return list<Topic> */
    public function findAllForProgramOrderedByTopicGroup(Program $program): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('g', 'go')
            ->innerJoin('t.topicGroup', 'g')
            ->leftJoin('g.options', 'go')
            ->where('t.program = :program')
            ->andWhere('t.inactiveDate IS NULL')
            ->setParameter('program', $program)
            ->orderBy('g.name', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Powers the Topics settings tab on App\Controller\ProgramTimetableSettingsController - same
    // reasoning as findAllForProgramOrderedByTopicGroup() above, but ordered by the topic group's
    // own Option short name first (a group common to every Option, i.e. no Option set, sorts
    // first - MySQL puts NULLs before any value in ASC order) so groups scoped to the same Option
    // end up adjacent, then by the group's own name (kept contiguous for RowGroup) and finally by
    // topic name.
    /** @return list<Topic> */
    public function findAllForProgramOrderedByOption(Program $program): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('g', 'go')
            ->innerJoin('t.topicGroup', 'g')
            ->leftJoin('g.options', 'go')
            ->where('t.program = :program')
            ->andWhere('t.inactiveDate IS NULL')
            ->setParameter('program', $program)
            ->orderBy('go.shortName', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
