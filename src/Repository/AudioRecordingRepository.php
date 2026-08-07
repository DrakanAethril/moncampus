<?php

namespace App\Repository;

use App\Entity\AudioRecording;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AudioRecording>
 */
class AudioRecordingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AudioRecording::class);
    }

    /**
     * A teacher's recordings on the given classes - their own only: a recording is teaching
     * material, it has no business circulating between colleagues of the same class. Staff, on the
     * other hand, see everything on the classes they consult.
     *
     * @param list<Program> $programs
     *
     * @return list<AudioRecording>
     */
    public function findForPrograms(array $programs, ?User $owner = null): array
    {
        if ([] === $programs) {
            return [];
        }

        $builder = $this->createQueryBuilder('r')
            ->addSelect('p', 'o', 'f', 'a')
            ->innerJoin('r.program', 'p')
            ->leftJoin('r.options', 'o')
            ->leftJoin('r.files', 'f')
            ->leftJoin('r.assignment', 'a')
            ->where('r.program IN (:programs)')
            ->setParameter('programs', $programs)
            ->orderBy('r.id', 'DESC');

        if (null !== $owner) {
            $builder->andWhere('r.createdBy = :owner')->setParameter('owner', $owner);
        }

        return $builder->getQuery()->getResult();
    }
}
