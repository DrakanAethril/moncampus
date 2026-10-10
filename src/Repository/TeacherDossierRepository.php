<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeacherDossier;
use App\Entity\User;
use App\Enum\TeacherDossierState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeacherDossier>
 *
 * Dossiers and models share the table (a model is a dossier with no intervenant), so **no method
 * here answers both**: every query says which of the two it reads, and none of them takes a flag.
 */
class TeacherDossierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeacherDossier::class);
    }

    /**
     * Every dossier - never a model - with its intervenant and its pieces, by intervenant then by
     * age. One query for the whole list: the screen then reads every dépôt in a second one
     * (App\Repository\TeacherDossierSubmissionRepository::findForDossiers()).
     *
     * @return list<TeacherDossier>
     */
    public function findDossiers(): array
    {
        /** @var list<TeacherDossier> $dossiers */
        $dossiers = $this->createQueryBuilder('d')
            ->addSelect('t', 'doc', 'tpl')
            ->innerJoin('d.teacher', 't')
            ->leftJoin('d.documents', 'doc')
            ->leftJoin('d.sourceTemplate', 'tpl')
            ->orderBy('t.lastname', 'ASC')
            ->addOrderBy('t.firstname', 'ASC')
            ->addOrderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $dossiers;
    }

    /**
     * The models, by title.
     *
     * @return list<TeacherDossier>
     */
    public function findTemplates(): array
    {
        /** @var list<TeacherDossier> $templates */
        $templates = $this->createQueryBuilder('d')
            ->addSelect('doc')
            ->leftJoin('d.documents', 'doc')
            ->where('d.teacher IS NULL')
            ->orderBy('d.title', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $templates;
    }

    /**
     * How many dossiers were made from each model - « 9 dossiers créés depuis ce modèle ».
     *
     * @return array<int, int> keyed by model id; a model nobody used is simply absent
     */
    public function countByTemplate(): array
    {
        /** @var list<array{templateId: int|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.sourceTemplate) AS templateId', 'COUNT(d.id) AS total')
            ->where('d.sourceTemplate IS NOT NULL')
            ->andWhere('d.teacher IS NOT NULL')
            ->groupBy('d.sourceTemplate')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['templateId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * What an intervenant reads under « Mes dossiers administratifs »: their dossiers once handed
     * to them, the closed ones included, newest first. A draft is not theirs to see yet.
     *
     * @return list<TeacherDossier>
     */
    public function findReadableForTeacher(User $teacher): array
    {
        return $this->findForTeacherInStates($teacher, [TeacherDossierState::Published, TeacherDossierState::Closed]);
    }

    /**
     * The dossiers still asking something of an intervenant - what the home banner reads. A closed
     * dossier asks for nothing any more.
     *
     * @return list<TeacherDossier>
     */
    public function findPublishedForTeacher(User $teacher): array
    {
        return $this->findForTeacherInStates($teacher, [TeacherDossierState::Published]);
    }

    /**
     * @param list<TeacherDossierState> $states
     *
     * @return list<TeacherDossier>
     */
    private function findForTeacherInStates(User $teacher, array $states): array
    {
        /** @var list<TeacherDossier> $dossiers */
        $dossiers = $this->createQueryBuilder('d')
            ->addSelect('doc')
            ->leftJoin('d.documents', 'doc')
            ->where('d.teacher = :teacher')
            ->andWhere('d.state IN (:states)')
            ->setParameter('teacher', $teacher)
            ->setParameter('states', $states)
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $dossiers;
    }
}
