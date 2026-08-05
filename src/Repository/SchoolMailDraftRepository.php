<?php

namespace App\Repository;

use App\Entity\SchoolMailDraft;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolMailDraft>
 */
class SchoolMailDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolMailDraft::class);
    }

    /**
     * The student's drafts, most recently touched first - the Drafts folder is a "what was I
     * writing" list, so the last thing typed belongs at the top.
     *
     * @return list<SchoolMailDraft>
     */
    public function findForStudent(User $student): array
    {
        return $this->findBy(['student' => $student], ['updatedAt' => 'DESC']);
    }

    public function countForStudent(User $student): int
    {
        return $this->count(['student' => $student]);
    }
}
