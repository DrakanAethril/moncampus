<?php

namespace App\Repository;

use App\Entity\LibraryBlocTag;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractLibraryTagRepository<LibraryBlocTag>
 */
class LibraryBlocTagRepository extends AbstractLibraryTagRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryBlocTag::class);
    }
}
