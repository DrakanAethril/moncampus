<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dossier;
use App\Entity\User;
use App\Enum\DossierState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Dossier>
 */
class DossierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dossier::class);
    }

    /**
     * The dossiers one validateur follows — theirs and the ones they were added to, drafts included.
     *
     * Membership of `dossier_validator` is the whole rule: the créateur is in that table like
     * everybody else (App\Entity\Dossier::addValidator() is called on them at creation), so there is
     * no second branch for « les miens ».
     *
     * @return list<Dossier>
     */
    public function findForValidator(User $validator): array
    {
        /** @var list<Dossier> $dossiers */
        $dossiers = $this->createQueryBuilder('d')
            ->innerJoin('d.validators', 'v')
            ->where('v = :validator')
            ->setParameter('validator', $validator)
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $dossiers;
    }

    /**
     * Every dossier, newest first - the administrator's reading of the same list.
     *
     * @return list<Dossier>
     */
    public function findAllNewestFirst(): array
    {
        /** @var list<Dossier> $dossiers */
        $dossiers = $this->createQueryBuilder('d')
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $dossiers;
    }

    /**
     * The published dossiers a student **might** be a cible of - the narrowing by option is not in
     * this query.
     *
     * Two doors, ORed: named one by one, or a student of one of the target formations. A draft never
     * appears - see App\Enum\DossierState.
     *
     * A target narrowed to an option (« SIO-2, les SLAM ») still comes back here for every student of
     * the class, and App\Service\Dossier\DossierTargetResolver::isTarget() is what takes the others
     * back out. Deliberate: the option link lives in `program_student_option`, and joining it here
     * would put the same rule in two places - one in DQL, one in PHP - with the DQL half being the
     * one nobody reads when the rule changes.
     *
     * @return list<Dossier>
     */
    public function findPublishedForStudent(User $student): array
    {
        /** @var list<Dossier> $dossiers */
        $dossiers = $this->createQueryBuilder('d')
            ->leftJoin('d.targetStudents', 'ts')
            ->leftJoin('d.targetPrograms', 't')
            ->leftJoin('t.program', 'tp')
            ->leftJoin('tp.students', 'tps')
            ->where('d.state = :published')
            ->andWhere('ts = :student OR tps = :student')
            ->setParameter('published', DossierState::Published)
            ->setParameter('student', $student)
            ->groupBy('d.id')
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $dossiers;
    }
}
