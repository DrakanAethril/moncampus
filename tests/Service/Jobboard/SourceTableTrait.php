<?php

declare(strict_types=1);

namespace App\Tests\Service\Jobboard;

use App\Entity\JobboardSource;
use App\Repository\JobboardSourceRepository;
use App\Service\Jobboard\JobboardSourceResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A sources table held in memory, for the tests that need one without a database.
 *
 * The four rows are the real ones, copied from the migration that seeded them - a resolver tested
 * against invented domains would prove nothing about the site names the veille actually sends.
 */
trait SourceTableTrait
{
    /** @return list<JobboardSource> */
    private function sourceTable(): array
    {
        return [
            (new JobboardSource('hellowork', 'HelloWork', ['hellowork.com']))->setLegacyPrefix('hw')->setRefRule("le nombre qui termine l'URL"),
            (new JobboardSource('francetravail', 'France Travail', ['francetravail.fr', 'pole-emploi.fr']))->setLegacyPrefix('ft')->setRefRule("le dernier segment de l'URL"),
            (new JobboardSource('jobteaser', 'Jobteaser', ['jobteaser.com']))->setLegacyPrefix('jt')->setRefRule("les 8 premiers caractères de l'UUID"),
            (new JobboardSource('meteojob', 'Meteojob', ['meteojob.com']))->setLegacyPrefix('mj')->setRefRule("le dernier segment de l'URL"),
        ];
    }

    private function sourceRepository(): JobboardSourceRepository
    {
        $repository = $this->createStub(JobboardSourceRepository::class);
        $repository->method('findAllOrdered')->willReturn($this->sourceTable());

        return $repository;
    }

    private function resolver(): JobboardSourceResolver
    {
        return new JobboardSourceResolver($this->sourceRepository(), $this->createStub(EntityManagerInterface::class));
    }
}
