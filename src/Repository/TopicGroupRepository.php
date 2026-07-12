<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\TopicGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TopicGroup>
 */
class TopicGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TopicGroup::class);
    }

    // Powers the TopicGroup dropdown on the Topic form and the Groupes de matières settings tab
    // on App\Controller\ProgramTimetableSettingsController - both need only active groups
    // ordered by name, and the settings tab additionally needs each group's own Options rendered
    // as badges, so options is eager-loaded here too to avoid an N+1 there.
    /** @return list<TopicGroup> */
    public function findAllActiveForProgram(Program $program): array
    {
        return $this->createQueryBuilder('tg')
            ->addSelect('o')
            ->leftJoin('tg.options', 'o')
            ->where('tg.program = :program')
            ->andWhere('tg.inactiveDate IS NULL')
            ->setParameter('program', $program)
            ->orderBy('tg.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
