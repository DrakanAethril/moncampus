<?php

namespace App\Repository;

use App\Entity\LibraryOptionTag;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractLibraryTagRepository<LibraryOptionTag>
 */
class LibraryOptionTagRepository extends AbstractLibraryTagRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryOptionTag::class);
    }
}
