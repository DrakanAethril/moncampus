<?php

namespace App\Repository;

use App\Entity\LibraryBlocTag;
use App\Entity\LibraryNiveauTag;
use App\Entity\LibraryOptionTag;
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
}
