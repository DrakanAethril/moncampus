<?php

namespace App\Repository;

use App\Entity\AbstractLibraryTag;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @template T of AbstractLibraryTag
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class AbstractLibraryTagRepository extends ServiceEntityRepository
{
    // Powers each tag field's create-or-reuse <select> (see App\Service\LibraryTagResolver) - a
    // teacher's own tags only, never another teacher's, matching the "no shared tags" requirement.
    // No pagination/limit: a single teacher's own vocabulary for one facet is expected to stay
    // small (a handful to a few dozen over years), unlike the app's real structural lists.
    /** @return list<T> */
    public function findAllForTeacher(User $teacher): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('t.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return T|null */
    public function findOneByTeacherAndLabel(User $teacher, string $label): ?AbstractLibraryTag
    {
        return $this->findOneBy(['teacher' => $teacher, 'label' => $label]);
    }
}
