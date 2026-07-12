<?php

namespace App\Repository;

use App\Entity\LibraryNiveauTag;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractLibraryTagRepository<LibraryNiveauTag>
 */
class LibraryNiveauTagRepository extends AbstractLibraryTagRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryNiveauTag::class);
    }
}
