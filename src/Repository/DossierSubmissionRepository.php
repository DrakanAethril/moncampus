<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Entity\DossierSubmission;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DossierSubmission>
 */
class DossierSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierSubmission::class);
    }

    /**
     * Every dépôt of a whole dossier, versions and échanges included, in one query.
     *
     * This is what makes the Suivi screen affordable: the grid is cibles × documents, so reading a
     * dépôt per cell would be six hundred queries. The two fetch-joins are the reason -
     * App\Service\Dossier\DossierStatusResolver reads `reviews` on every row it is handed.
     *
     * @return list<DossierSubmission>
     */
    public function findForDossier(Dossier $dossier): array
    {
        /** @var list<DossierSubmission> $submissions */
        $submissions = $this->createQueryBuilder('s')
            ->addSelect('r', 'a')
            ->innerJoin('s.document', 'd')
            ->leftJoin('s.reviews', 'r')
            ->leftJoin('r.author', 'a')
            ->where('d.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('s.version', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $submissions;
    }

    /**
     * The dépôts of one cible on one dossier - the student's own screen.
     *
     * @return list<DossierSubmission>
     */
    public function findForDossierAndStudent(Dossier $dossier, User $student): array
    {
        /** @var list<DossierSubmission> $submissions */
        $submissions = $this->createQueryBuilder('s')
            ->addSelect('r', 'a')
            ->innerJoin('s.document', 'd')
            ->leftJoin('s.reviews', 'r')
            ->leftJoin('r.author', 'a')
            ->where('d.dossier = :dossier')
            ->andWhere('s.student = :student')
            ->setParameter('dossier', $dossier)
            ->setParameter('student', $student)
            ->orderBy('s.version', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $submissions;
    }

    /** The version number the next dépôt of this cible on this document must carry. */
    public function nextVersion(DossierDocument $document, User $student): int
    {
        /** @var int|null $highest */
        $highest = $this->createQueryBuilder('s')
            ->select('MAX(s.version)')
            ->where('s.document = :document')
            ->andWhere('s.student = :student')
            ->setParameter('document', $document)
            ->setParameter('student', $student)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 1 : $highest + 1;
    }
}
