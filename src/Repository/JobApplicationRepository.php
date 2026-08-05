<?php

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
