<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierDocument;
use App\Entity\TeacherDossierSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeacherDossierSubmission>
 */
class TeacherDossierSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeacherDossierSubmission::class);
    }

    /**
     * Every dépôt of several dossiers, versions and reviews included, in one query.
     *
     * This is what makes the list affordable: each row of it shows an avancement and its points
     * d'attention, which are read from the dépôts - a query per dossier would be one per row.
     *
     * @param list<TeacherDossier> $dossiers
     *
     * @return list<TeacherDossierSubmission>
     */
    public function findForDossiers(array $dossiers): array
    {
        if ([] === $dossiers) {
            return [];
        }

        /** @var list<TeacherDossierSubmission> $submissions */
        $submissions = $this->createQueryBuilder('s')
            ->addSelect('r', 'a', 'depositor')
            ->innerJoin('s.document', 'd')
            ->leftJoin('s.reviews', 'r')
            ->leftJoin('r.author', 'a')
            ->leftJoin('s.submittedBy', 'depositor')
            ->where('d.dossier IN (:dossiers)')
            ->setParameter('dossiers', $dossiers)
            ->orderBy('s.version', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $submissions;
    }

    /**
     * @return list<TeacherDossierSubmission>
     */
    public function findForDocument(TeacherDossierDocument $document): array
    {
        /** @var list<TeacherDossierSubmission> $submissions */
        $submissions = $this->createQueryBuilder('s')
            ->addSelect('r')
            ->leftJoin('s.reviews', 'r')
            ->where('s.document = :document')
            ->setParameter('document', $document)
            ->orderBy('s.version', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $submissions;
    }

    /** Has anything ever been handed in on this piece? A piece that has is no longer deleted. */
    public function hasAny(TeacherDossierDocument $document): bool
    {
        return $this->count(['document' => $document]) > 0;
    }

    /** The version number the next dépôt on this piece must carry. */
    public function nextVersion(TeacherDossierDocument $document): int
    {
        /** @var int|string|null $highest */
        $highest = $this->createQueryBuilder('s')
            ->select('MAX(s.version)')
            ->where('s.document = :document')
            ->setParameter('document', $document)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 1 : (int) $highest + 1;
    }

    /**
     * The storage keys of every file handed in on a dossier - what deleting the dossier has to
     * hand to the deferred purge.
     *
     * @return list<string>
     */
    public function storageKeysOf(TeacherDossier $dossier): array
    {
        /** @var list<string> $keys */
        $keys = $this->createQueryBuilder('s')
            ->select('s.storageKey')
            ->innerJoin('s.document', 'd')
            ->where('d.dossier = :dossier')
            ->andWhere('s.storageKey IS NOT NULL')
            ->setParameter('dossier', $dossier)
            ->getQuery()
            ->getSingleColumnResult();

        return $keys;
    }
}
