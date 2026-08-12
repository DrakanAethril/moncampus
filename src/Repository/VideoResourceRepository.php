<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
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
