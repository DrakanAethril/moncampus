<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LibraryBlocTag;
use App\Entity\LibraryNiveauTag;
use App\Entity\LibraryOptionTag;
use App\Entity\SequenceFolder;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SequenceTemplate>
 */
class SequenceTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SequenceTemplate::class);
    }

    // Powers App\Controller\SequenceLibraryController::list() - the niveau/option/bloc filters
    // are optional, narrowing the teacher's own sequences down to the ones tagged with that exact
    // free-text tag (see SequenceTemplate's own docblock on why these are per-teacher tags, not
    // the real Cohort/Option/Bloc entities).
    /** @return list<SequenceTemplate> */
    public function findForTeacher(User $teacher, ?LibraryNiveauTag $niveau = null, ?LibraryOptionTag $option = null, ?LibraryBlocTag $bloc = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('n', 'o')
            ->leftJoin('s.niveau', 'n')
            ->leftJoin('s.option', 'o')
            ->where('s.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('s.order', 'ASC')
            ->addOrderBy('s.creationDate', 'DESC');

        if (null !== $niveau) {
            $qb->andWhere('s.niveau = :niveau')->setParameter('niveau', $niveau);
        }

        if (null !== $option) {
            $qb->andWhere('s.option = :option')->setParameter('option', $option);
        }

        if (null !== $bloc) {
            $qb->andWhere(':bloc MEMBER OF s.blocs')->setParameter('bloc', $bloc);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The séquence with everything the « Quiz de la séquence » card needs, in one query.
     *
     * Two collections of quizzes, on two levels, read for every séance: without the fetch-joins the
     * card costs one query per séance, which on the Ansible kit's four is four - and on a séquence of
     * twenty is twenty. Same reflex as the dashboard's audience N+1.
     *
     * `find()` would still be correct and slower, which is exactly why this exists rather than a
     * comment asking the next reader to remember.
     */
    public function findWithQuizzes(int $id): ?SequenceTemplate
    {
        return $this->createQueryBuilder('s')
            ->addSelect('seance', 'sequenceQuiz', 'seanceQuiz')
            ->leftJoin('s.seanceTemplates', 'seance')
            ->leftJoin('s.quizTemplates', 'sequenceQuiz')
            ->leftJoin('seance.quizTemplates', 'seanceQuiz')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The séquences of one folder - null being the root of the library - keeping the three tag
     * filters the screen has always offered.
     *
     * **The manual order is folder-local**, and that is what the classement changed: the drag on the
     * ⠿ handle reassigns positions among the rows of the folder being looked at
     * (App\Controller\SequenceLibraryController::sequencesReorder(), which only ever touches the ids
     * it is handed), so two folders order themselves without knowing about each other.
     *
     * @return list<SequenceTemplate>
     */
    public function findInFolder(User $teacher, ?SequenceFolder $folder, ?LibraryNiveauTag $niveau = null, ?LibraryOptionTag $option = null, ?LibraryBlocTag $bloc = null): array
    {
        $builder = $this->createQueryBuilder('s')
            ->addSelect('n', 'o')
            ->leftJoin('s.niveau', 'n')
            ->leftJoin('s.option', 'o')
            ->where('s.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('s.order', 'ASC')
            ->addOrderBy('s.creationDate', 'DESC');

        if (null === $folder) {
            $builder->andWhere('s.folder IS NULL');
        } else {
            $builder->andWhere('s.folder = :folder')->setParameter('folder', $folder);
        }

        if (null !== $niveau) {
            $builder->andWhere('s.niveau = :niveau')->setParameter('niveau', $niveau);
        }

        if (null !== $option) {
            $builder->andWhere('s.option = :option')->setParameter('option', $option);
        }

        if (null !== $bloc) {
            $builder->andWhere(':bloc MEMBER OF s.blocs')->setParameter('bloc', $bloc);
        }

        /** @var list<SequenceTemplate> $sequences */
        $sequences = $builder->getQuery()->getResult();

        return $sequences;
    }

    /**
     * A title search over the whole library, folders ignored - the way back to a séquence whose
     * folder the teacher has forgotten, which is what a classement takes away from a flat list.
     *
     * @return list<SequenceTemplate>
     */
    public function searchByTitle(User $teacher, string $terms): array
    {
        /** @var list<SequenceTemplate> $sequences */
        $sequences = $this->createQueryBuilder('s')
            ->addSelect('n', 'o')
            ->leftJoin('s.niveau', 'n')
            ->leftJoin('s.option', 'o')
            ->where('s.teacher = :teacher')
            ->andWhere('s.titre LIKE :terms')
            ->setParameter('teacher', $teacher)
            ->setParameter('terms', '%'.addcslashes($terms, '%_').'%')
            ->orderBy('s.titre', 'ASC')
            ->getQuery()
            ->getResult();

        return $sequences;
    }

    /**
     * How many séquences a folder holds, at any depth - the folder row's own count.
     *
     * Read off the descendants' materialized path rather than by walking the tree: one query for a
     * whole branch, the same reading App\Repository\QuizTemplateRepository::countInSubtree() does.
     */
    public function countInSubtree(SequenceFolder $folder): int
    {
        $count = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.folder', 'f')
            ->where('s.teacher = :teacher')
            ->andWhere('f.owner = :owner')
            ->andWhere('f.id = :id OR f.path LIKE :pattern')
            ->setParameter('teacher', $folder->getOwner())
            ->setParameter('owner', $folder->getOwner())
            ->setParameter('id', $folder->getId())
            ->setParameter('pattern', $folder->childPath().'%')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * The last manual position used inside a folder - what a séquence arriving there is given, so a
     * move lands it at the end rather than in the middle of an order somebody arranged by hand.
     */
    public function maxOrderIn(User $teacher, ?SequenceFolder $folder): int
    {
        $builder = $this->createQueryBuilder('s')
            ->select('MAX(s.order)')
            ->where('s.teacher = :teacher')
            ->setParameter('teacher', $teacher);

        if (null === $folder) {
            $builder->andWhere('s.folder IS NULL');
        } else {
            $builder->andWhere('s.folder = :folder')->setParameter('folder', $folder);
        }

        return (int) $builder->getQuery()->getSingleScalarResult();
    }
}
