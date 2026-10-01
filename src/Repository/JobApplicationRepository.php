<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobApplication;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobApplication>
 */
class JobApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobApplication::class);
    }

    /**
     * A student's démarches, mails already loaded: screens 2a and 2b count their mails, which would
     * be an N+1 without this.
     *
     * @return list<JobApplication>
     */
    public function findForStudent(User $student): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('m')
            ->leftJoin('a.emailMessages', 'm')
            ->andWhere('a.student = :student')
            ->setParameter('student', $student)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The démarche a name designates for this student in this class - the lookup behind "or pick an
     * existing one": typing a name already used lands the mail in that same démarche.
     *
     * Matching is left to the database's collation, which is case-insensitive: "Néopixel" and
     * "néopixel" are the same démarche to the student who typed them.
     */
    public function findOneForStudentAndName(User $student, ?Program $program, string $name): ?JobApplication
    {
        $builder = $this->createQueryBuilder('a')
            ->andWhere('a.name = :name')
            ->setParameter('name', trim($name))
            ->setMaxResults(1);

        return $this->scopeToStudent($builder, $student, $program)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The names already used by this student in this class, for the compose screen's suggestion
     * list.
     *
     * @return list<string>
     */
    public function findNamesForStudent(User $student, ?Program $program): array
    {
        $builder = $this->createQueryBuilder('a')
            ->select('a.name')
            ->orderBy('a.name', 'ASC');

        $rows = $this->scopeToStudent($builder, $student, $program)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['name'], $rows);
    }

    /**
     * This student's démarches about these establishments of the register, every class included -
     * « Dans mes démarches » on a page of results.
     *
     * @param list<string> $sirets
     *
     * @return array<string, JobApplication> keyed by SIRET, the most recently created one kept
     */
    public function findForStudentBySirets(User $student, array $sirets): array
    {
        if ([] === $sirets) {
            return [];
        }

        $found = [];
        foreach ($this->createQueryBuilder('a')
            ->addSelect('m')
            ->leftJoin('a.emailMessages', 'm')
            ->andWhere('a.student = :student')
            ->andWhere('a.siret IN (:sirets)')
            ->setParameter('student', $student)
            ->setParameter('sirets', array_values(array_unique($sirets)))
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult() as $application) {
            $found[(string) $application->getSiret()] = $application;
        }

        return $found;
    }

    /** @return list<string> every SIRET this student has a démarche about */
    public function findSiretsForStudent(User $student): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.siret AS siret')
            ->andWhere('a.student = :student')
            ->andWhere('a.siret IS NOT NULL')
            ->setParameter('student', $student)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['siret'] ?? ''), $rows)));
    }

    /**
     * How many démarches « à écrire » - nothing sent, nothing received - each of these students has.
     *
     * @param list<User> $students
     *
     * @return array<int, int> keyed by student id
     */
    public function countToWriteByStudent(array $students): array
    {
        if ([] === $students) {
            return [];
        }

        /** @var list<array{student: int|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.student) AS student, COUNT(a.id) AS total')
            ->leftJoin('a.emailMessages', 'm')
            ->andWhere('a.student IN (:students)')
            ->andWhere('m.id IS NULL')
            ->setParameter('students', $students)
            ->groupBy('a.student')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['student']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * A démarche belongs to a student *and* to the class they opened it in, and a null program is a
     * value of its own here - hence IS NULL rather than a parameter, which would never match.
     */
    private function scopeToStudent(QueryBuilder $builder, User $student, ?Program $program): QueryBuilder
    {
        $builder
            ->andWhere('a.student = :student')
            ->setParameter('student', $student);

        if (null === $program) {
            return $builder->andWhere('a.program IS NULL');
        }

        return $builder
            ->andWhere('a.program = :program')
            ->setParameter('program', $program);
    }
}
