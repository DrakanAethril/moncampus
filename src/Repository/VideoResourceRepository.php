<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\User;
use App\Entity\VideoResource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoResource>
 */
class VideoResourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoResource::class);
    }

    /**
     * The videos of several programs at once - what the "Vidéos" tool lists, newest first.
     *
     * $owner narrows the list to somebody's own videos: a video is teaching material, not a resource
     * of the class, so a colleague teaching the same programme does not see it. Staff pass null and
     * see everything, as they do on the audio list.
     *
     * @param list<Program> $programs
     *
     * @return list<VideoResource>
     */
    public function findForPrograms(array $programs, ?User $owner = null): array
    {
        if ([] === $programs) {
            return [];
        }

        $builder = $this->createQueryBuilder('v')
            ->addSelect('p', 'o', 'f', 'a')
            ->innerJoin('v.program', 'p')
            ->leftJoin('v.options', 'o')
            ->leftJoin('v.files', 'f')
            ->leftJoin('v.assignment', 'a')
            ->where('v.program IN (:programs)')
            ->setParameter('programs', $programs)
            ->orderBy('v.id', 'DESC');

        if (null !== $owner) {
            $builder->andWhere('v.createdBy = :owner')->setParameter('owner', $owner);
        }

        /** @var list<VideoResource> $resources */
        $resources = $builder->getQuery()->getResult();

        return $resources;
    }

    /**
     * The videos of one program, newest first, with their files already loaded.
     *
     * Fetch-joined on purpose: every screen listing videos also shows how many files each holds and
     * how long they run, so leaving the collection lazy turns one query into one per row - the N+1
     * this codebase has already paid for once on the home dashboard.
     *
     * @return list<VideoResource>
     */
    public function findForProgram(Program $program): array
    {
        /** @var list<VideoResource> $resources */
        $resources = $this->createQueryBuilder('v')
            ->leftJoin('v.files', 'f')->addSelect('f')
            ->where('v.program = :program')
            ->setParameter('program', $program)
            ->orderBy('v.creationDate', 'DESC')
            ->getQuery()
            ->getResult();

        return $resources;
    }
}
