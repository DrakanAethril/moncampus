<?php

namespace App\Repository;

use App\Entity\LessonSession;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LessonSession>
 */
class LessonSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LessonSession::class);
    }

    // Fetch-joins everything the weekly calendar feed needs to render each session (teacher,
    // room, lesson type, options) in a single query, since this runs on every calendar load.
    /** @return list<LessonSession> */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('t', 'r', 'lt', 'o')
            ->leftJoin('l.teacher', 't')
            ->leftJoin('l.classRoom', 'r')
            ->leftJoin('l.lessonType', 'lt')
            ->leftJoin('l.options', 'o')
            ->where('l.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();
    }

    // Same PHP-side aggregation approach as App\Service\ProgramFinancialCalculator::getHoursPerLessonType()
    // (LessonSession::$length is manually entered, there's no DQL SUM() equivalent elsewhere in
    // the app) - powers the "planned/scheduled hours" column on the Topics settings tab. Sessions
    // with no Topic (title-only sessions) are naturally excluded by the innerJoin.
    /** @return array<int, float> Topic id => total hours scheduled for this program */
    public function findHoursByTopicForProgram(Program $program): array
    {
        $hoursByTopicId = [];

        $sessions = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.topic) AS topicId', 'l.length')
            ->innerJoin('l.topic', 't')
            ->where('l.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        foreach ($sessions as $session) {
            $topicId = (int) $session['topicId'];
            $hoursByTopicId[$topicId] = ($hoursByTopicId[$topicId] ?? 0.0) + (float) $session['length'];
        }

        return $hoursByTopicId;
    }

    // Powers the exports (signature sheets, invoicing) - both need every session in a staff-
    // picked date range, ordered so a day's sessions print left-to-right in chronological order.
    /** @return list<LessonSession> */
    public function findForProgramBetween(Program $program, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('t', 'lt', 'o')
            ->leftJoin('l.teacher', 't')
            ->leftJoin('l.lessonType', 'lt')
            ->leftJoin('l.options', 'o')
            ->where('l.program = :program')
            ->andWhere('l.day BETWEEN :start AND :end')
            ->setParameter('program', $program)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('l.day', 'ASC')
            ->addOrderBy('l.startHour', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
