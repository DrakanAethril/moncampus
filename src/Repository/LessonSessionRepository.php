<?php

namespace App\Repository;

use App\Entity\LessonLog;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
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

    // Powers the teacher home dashboard's "upcoming sessions" widget - a teacher's own sessions
    // across every Program they teach, unlike findForProgramBetween() which is scoped to one.
    /** @return list<LessonSession> */
    public function findUpcomingForTeacher(User $teacher, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('p', 'r', 't')
            ->innerJoin('l.program', 'p')
            ->leftJoin('l.classRoom', 'r')
            ->leftJoin('l.topic', 't')
            ->where('l.teacher = :teacher')
            ->andWhere('l.day BETWEEN :from AND :to')
            ->setParameter('teacher', $teacher)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('l.day', 'ASC')
            ->addOrderBy('l.startHour', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Powers the teacher home dashboard's "sessions missing a cahier de texte" widget - LessonLog
    // has a unidirectional OneToOne to LessonSession (owning side on LessonLog, no inverse
    // property), so "no log yet" can only be expressed as a cross-entity LEFT JOIN ... IS NULL,
    // not a property path on LessonSession itself.
    /** @return list<LessonSession> */
    public function findRecentWithoutLogForTeacher(User $teacher, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('p', 't')
            ->innerJoin('l.program', 'p')
            ->leftJoin('l.topic', 't')
            ->leftJoin(LessonLog::class, 'log', 'WITH', 'log.lessonSession = l')
            ->where('l.teacher = :teacher')
            ->andWhere('l.day BETWEEN :since AND :until')
            ->andWhere('log.id IS NULL')
            ->setParameter('teacher', $teacher)
            ->setParameter('since', $since)
            ->setParameter('until', $until)
            ->orderBy('l.day', 'ASC')
            ->addOrderBy('l.startHour', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
