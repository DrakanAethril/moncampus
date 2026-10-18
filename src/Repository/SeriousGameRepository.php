<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGame;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGame>
 */
class SeriousGameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGame::class);
    }

    public function findOneBySlug(string $slug): ?SeriousGame
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return list<SeriousGame> every game, in title order */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['title' => 'ASC']);
    }

    /** @return list<SeriousGame> */
    public function findPublished(): array
    {
        return $this->findBy(['published' => true], ['title' => 'ASC']);
    }
}
