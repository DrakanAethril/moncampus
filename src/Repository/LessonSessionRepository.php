<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LessonLog;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
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

    // The créneaux a Progression can place séances on: every timetable slot carrying this exact
    // Topic, in the chronological order App\Service\ProgressionPlacementService walks them
    // (design/design_handoff_progression/README.md §4.6, "la première heure de cours de la
    // matière"). A slot with no Topic set is simply not a slot for any progression.
    /** @return list<LessonSession> */
    public function findOrderedForTopic(Topic $topic): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('r', 'o')
            ->leftJoin('l.classRoom', 'r')
            ->leftJoin('l.options', 'o')
            ->where('l.topic = :topic')
            ->setParameter('topic', $topic)
            ->orderBy('l.day', 'ASC')
            ->addOrderBy('l.startHour', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The same list over SEVERAL matières at once, for a séquence whose "Créneaux utilisés" reaches
     * beyond the progression's own Topic (App\Enum\ProgressionSlotTopicScope). One ordered list for
     * the whole progression is what the placement service walks, so it has to be one query rather
     * than a per-matière list concatenated afterwards: the cursor it carries from one séquence to
     * the next is an index into that single chronological order.
     *
     * @param list<Topic> $topics
     *
     * @return list<LessonSession>
     */
    public function findOrderedForTopics(array $topics): array
    {
        if ([] === $topics) {
            return [];
        }

        return $this->createQueryBuilder('l')
            ->addSelect('r', 'o')
            ->leftJoin('l.classRoom', 'r')
            ->leftJoin('l.options', 'o')
            ->where('l.topic IN (:topics)')
            ->setParameter('topics', $topics)
            ->orderBy('l.day', 'ASC')
            ->addOrderBy('l.startHour', 'ASC')
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
    // picked date range, ordered so a day's sessions print left-to-right in chronological order -
    // as well as the calendar feeds, whose range is that of the week displayed. The room is joined
    // for the latter (LessonSessionEventFormatter reads it on every event); it is one more to-one
    // join, with no effect on the number of rows returned.
    /** @return list<LessonSession> */
    public function findForProgramBetween(Program $program, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('t', 'r', 'lt', 'o')
            ->leftJoin('l.teacher', 't')
            ->leftJoin('l.classRoom', 'r')
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

    // Powers the left column of the teacher home dashboard (design_handoff_dashboards ens-a): one
    // day's sessions for a teacher, test Programs left out. Deliberately not the same query as
    // findAllForTeacherBetween() - the dashboard answers "what am I actually teaching", while the
    // teacher's own timetable must keep showing test Programs so they can be worked on there.
    /** @return list<LessonSession> */
    public function findForTeacherOnDayMatchingTestMode(User $teacher, \DateTimeImmutable $day): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('p', 'r', 'lt', 'o')
            ->innerJoin('l.program', 'p')
            ->leftJoin('l.classRoom', 'r')
            ->leftJoin('l.lessonType', 'lt')
            ->leftJoin('l.options', 'o')
            ->where('l.teacher = :teacher')
            ->andWhere('l.day = :day')
            ->andWhere('p.testProgram = :testMode')
            ->setParameter('teacher', $teacher)
            ->setParameter('day', $day)
            ->setParameter('testMode', $teacher->isTestUser())
            ->orderBy('l.startHour', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // The next day *after* $day on which the teacher has any session at all (test Programs left
    // out), or null when they have none left - the dashboard's day column falls back to that day
    // instead of dead-ending on an empty "aucun cours aujourd'hui" whenever today happens to be
    // free. Unbounded on purpose: the point is to find the next teaching day however far off it is.
    public function findNextSessionDayForTeacher(User $teacher, \DateTimeImmutable $day): ?\DateTimeImmutable
    {
        $nextDay = $this->createQueryBuilder('l')
            ->select('MIN(l.day)')
            ->innerJoin('l.program', 'p')
            ->where('l.teacher = :teacher')
            ->andWhere('l.day > :day')
            ->andWhere('p.testProgram = :testMode')
            ->setParameter('teacher', $teacher)
            ->setParameter('day', $day)
            ->setParameter('testMode', $teacher->isTestUser())
            ->getQuery()
            ->getSingleScalarResult();

        return null === $nextDay ? null : new \DateTimeImmutable((string) $nextDay);
    }

    /**
     * Powers the teacher dashboard's "Mes prochaines séances par classe" card: each Program's very
     * next session for this teacher, strictly ahead of $now (a session that already ended today no
     * longer counts) and never from a test Program. Programs with nothing left ahead are simply
     * absent from the result, which is what drops them from the card.
     *
     * Two steps because DQL has no per-group LIMIT: collect each Program's earliest remaining day
     * as scalars, then fetch only those days and keep the first session per Program. Ordering by
     * day then startHour makes "first seen" the right one - a Program can only appear on days at
     * or after its own earliest remaining day.
     *
     * @return array<int, LessonSession> keyed by Program id
     */
    public function findNextSessionPerProgramForTeacher(User $teacher, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
        $rows = $this->upcomingForTeacherQueryBuilder($teacher, $today, $now)
            ->select('IDENTITY(l.program) AS programId', 'MIN(l.day) AS nextDay')
            ->groupBy('l.program')
            ->getQuery()
            ->getArrayResult();

        if ([] === $rows) {
            return [];
        }

        $sessions = $this->upcomingForTeacherQueryBuilder($teacher, $today, $now)
            ->addSelect('p', 'r', 't')
            ->leftJoin('l.classRoom', 'r')
            ->leftJoin('l.topic', 't')
            ->andWhere('l.program IN (:programs)')
            ->andWhere('l.day IN (:days)')
            ->setParameter('programs', array_column($rows, 'programId'))
            // Bound as "Y-m-d" strings: Doctrine has no type inference for an *array* of
            // DateTimeImmutable and would try to stringify each one.
            ->setParameter('days', array_map(static fn (array $row): string => (new \DateTimeImmutable((string) $row['nextDay']))->format('Y-m-d'), $rows))
            ->orderBy('l.day', 'ASC')
            ->addOrderBy('l.startHour', 'ASC')
            ->getQuery()
            ->getResult();

        $nextByProgramId = [];
        foreach ($sessions as $session) {
            $nextByProgramId[$session->getProgram()->getId()] ??= $session;
        }

        return $nextByProgramId;
    }

    // Shared skeleton of the two findNextSessionPerProgramForTeacher() queries: this teacher's
    // sessions that are still ahead, test Programs excluded. $now is bound as a TIME rather than
    // left to Doctrine's datetime inference - end_hour is a TIME column, and MySQL turns a full
    // "Y-m-d H:i:s" string compared against one into a truncation warning plus NULL, i.e. a filter
    // that silently matches nothing.
    private function upcomingForTeacherQueryBuilder(User $teacher, \DateTimeImmutable $today, \DateTimeImmutable $now): QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->innerJoin('l.program', 'p')
            ->where('l.teacher = :teacher')
            ->andWhere('p.testProgram = :testMode')
            ->andWhere('l.day > :today OR (l.day = :today AND l.endHour > :now)')
            ->setParameter('teacher', $teacher)
            ->setParameter('today', $today)
            ->setParameter('now', $now, Types::TIME_IMMUTABLE)
            ->setParameter('testMode', $teacher->isTestUser());
    }

    // Powers the teacher's personal cross-Program timetable (App\Controller\TeacherTimetableController)
    // - same shape as findForProgram() (fetch-joins everything LessonSessionEventFormatter needs
    // to render an event: room, lesson type, options; program/topic resolve for free through
    // Doctrine's identity map/lazy load without a dedicated join, teacher likewise since every
    // row already matches the given $teacher), but scoped across every Program a teacher teaches
    // in and bounded by the calendar's currently visible date range instead - unlike
    // findForProgram(), a teacher's whole multi-year session history would otherwise load at once.
    /** @return list<LessonSession> */
    public function findAllForTeacherBetween(User $teacher, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('p', 'r', 'lt', 'o')
            ->innerJoin('l.program', 'p')
            ->leftJoin('l.classRoom', 'r')
            ->leftJoin('l.lessonType', 'lt')
            ->leftJoin('l.options', 'o')
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

    // Powers the legend on the teacher's personal timetable (App\Controller\TeacherTimetableController)
    // - every Program a teacher has ANY session in, regardless of date, so the legend stays the
    // same set of formations as the teacher navigates weeks instead of flickering in/out as
    // findAllForTeacherBetween()'s own date-bounded events come and go.
    /** @return list<Program> */
    public function findDistinctProgramsForTeacher(User $teacher): array
    {
        // DQL can't SELECT DISTINCT an entity through a joined alias ("Cannot select entity
        // through identification variables without choosing at least one root entity alias") -
        // collect the distinct Program ids as scalars first, then fetch the actual entities.
        $programIds = $this->createQueryBuilder('l')
            ->select('DISTINCT IDENTITY(l.program) AS programId')
            ->where('l.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $programIds) {
            return [];
        }

        return $this->getEntityManager()->getRepository(Program::class)->findBy(['id' => $programIds], ['shortName' => 'ASC']);
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

    // Powers the staff dashboard's "Emploi du temps du jour — toutes les classes" matrix
    // (design_handoff_dashboards staff-a): every session of one day across every active Program,
    // fetch-joined down to the cohort (whose color paints the matrix rows/legend). Test Programs
    // are left out, same rule as the teacher dashboard's own queries.
    /** @return list<LessonSession> */
    public function findAllForDay(\DateTimeImmutable $day, bool $testMode = false): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('p', 'c', 'ct', 'r', 't', 'te')
            ->innerJoin('l.program', 'p')
            ->innerJoin('p.cohort', 'c')
            ->innerJoin('c.track', 'ct')
            ->leftJoin('l.classRoom', 'r')
            ->leftJoin('l.topic', 't')
            ->leftJoin('l.teacher', 'te')
            ->where('l.day = :day')
            ->andWhere('p.inactiveDate IS NULL')
            ->andWhere('p.testProgram = :testMode')
            ->setParameter('day', $day)
            ->setParameter('testMode', $testMode)
            ->orderBy('p.shortName', 'ASC')
            ->addOrderBy('l.startHour', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Sibling of findAllForDay() for the staff dashboard's day fallback: the next day after $day
    // carrying a session in any active, non-test Program, or null when there is none left - same
    // filters as findAllForDay(), so the day it hands back can never render an empty matrix.
    public function findNextSessionDayForAnyProgram(\DateTimeImmutable $day, bool $testMode = false): ?\DateTimeImmutable
    {
        $nextDay = $this->createQueryBuilder('l')
            ->select('MIN(l.day)')
            ->innerJoin('l.program', 'p')
            ->where('l.day > :day')
            ->andWhere('p.inactiveDate IS NULL')
            ->andWhere('p.testProgram = :testMode')
            ->setParameter('day', $day)
            ->setParameter('testMode', $testMode)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $nextDay ? null : new \DateTimeImmutable((string) $nextDay);
    }

    /**
     * The next day after $day carrying a session in any of $programs, for the student dashboard's
     * "prochaine journée de cours" fallback - the same rule the teacher and staff cards already
     * follow, so a student landing on a free day sees their next lesson day instead of nothing.
     *
     * No test-Program filter here: the caller passes the Programs the student is actually enrolled
     * in, and that list is already on one side of the test fence or the other (see
     * ProgramRepository::findAllActiveForStudent()). Filtering again would be a second, weaker
     * copy of the same rule.
     *
     * @param list<Program> $programs
     */
    public function findNextSessionDayForPrograms(array $programs, \DateTimeImmutable $day): ?\DateTimeImmutable
    {
        if ([] === $programs) {
            return null;
        }

        $nextDay = $this->createQueryBuilder('l')
            ->select('MIN(l.day)')
            ->where('l.program IN (:programs)')
            ->andWhere('l.day > :day')
            ->setParameter('programs', $programs)
            ->setParameter('day', $day)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $nextDay ? null : new \DateTimeImmutable((string) $nextDay);
    }

    // Mirror of findNextSessionDayForAnyProgram() for the staff dashboard's "jour précédent"
    // arrow. Both back the same rule: the arrows step from one day that actually has classes to
    // the next, never through the empty days in between (weekends, holidays, alternance weeks),
    // and hand back null when there is nothing further that way - which is what disables the arrow.
    public function findPreviousSessionDayForAnyProgram(\DateTimeImmutable $day, bool $testMode = false): ?\DateTimeImmutable
    {
        $previousDay = $this->createQueryBuilder('l')
            ->select('MAX(l.day)')
            ->innerJoin('l.program', 'p')
            ->where('l.day < :day')
            ->andWhere('p.inactiveDate IS NULL')
            ->andWhere('p.testProgram = :testMode')
            ->setParameter('day', $day)
            ->setParameter('testMode', $testMode)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $previousDay ? null : new \DateTimeImmutable((string) $previousDay);
    }

    /**
     * The séances comparable to this one whose cahier de texte says something: the same lesson,
     * elsewhere - to another group this year, or in previous years.
     *
     * « The same lesson » is recognised by the matière name: each program has its own Topic, and two
     * groups following the same lesson have two homonymous Topics. It is the only link the model
     * offers between them, for want of a shared matière reference framework.
     *
     * The most recent first, the current séance and its own program excluded.
     *
     * @return list<LessonSession>
     */
    public function findComparableFilledSessions(LessonSession $session, int $limit = 20): array
    {
        $topicName = $session->getTopic()?->getName();
        if (null === $topicName || null === $session->getProgram()) {
            return [];
        }

        return $this->createQueryBuilder('l')
            ->addSelect('p', 'tp')
            ->innerJoin('l.program', 'p')
            ->innerJoin('l.topic', 'tp')
            ->innerJoin(LessonLog::class, 'log', \Doctrine\ORM\Query\Expr\Join::WITH, 'log.lessonSession = l')
            ->where('tp.name = :topicName')
            ->andWhere('l.id != :session')
            ->andWhere('p.id != :program')
            // An empty cahier de texte has nothing to give: at least one of the three parts must say
            // something.
            ->andWhere("COALESCE(log.contenuRealise, '') != '' OR COALESCE(log.travailAvantDescription, '') != '' OR COALESCE(log.travailApresDescription, '') != ''")
            ->setParameter('topicName', $topicName)
            ->setParameter('session', $session->getId())
            ->setParameter('program', $session->getProgram()->getId())
            ->orderBy('l.day', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
