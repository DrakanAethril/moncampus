<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Counter\CounterRecomputer;
use App\Entity\User;
use App\Service\FileLibraryNodeManager;
use App\Service\FileLibraryQuota;
use App\Service\FileLibraryUsageCounter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The stored weight of a file library follows every gesture that changes it - creating, replacing,
 * trashing, restoring, deleting for good - because it is moved by an entity listener rather than by
 * each code path. After each gesture the recomputation, summing the live files again, must find
 * nothing to correct.
 */
class FileLibraryUsageCounterTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private FileLibraryNodeManager $manager;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->manager = static::getContainer()->get(FileLibraryNodeManager::class);
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'library.weight');
    }

    public function testTheStoredWeightFollowsEveryGesture(): void
    {
        $folder = $this->manager->createFolder($this->teacher, null, 'Cours');
        $this->entityManager->flush();
        $this->assertWeight(0, 'a folder weighs nothing');

        $a = $this->manager->createFile($this->teacher, $folder, 'a.pdf', 'file-library/a', 'a.pdf', 'application/pdf', 1000);
        $b = $this->manager->createFile($this->teacher, $folder, 'b.pdf', 'file-library/b', 'b.pdf', 'application/pdf', 250);
        $this->entityManager->flush();
        $this->assertWeight(1250, 'two files created');

        $this->manager->replace($a, 'file-library/a2', 'a.pdf', 'application/pdf', 4000, $this->teacher);
        $this->entityManager->flush();
        $this->assertWeight(4250, 'a replaced by a heavier version');

        $this->manager->trash($folder, $this->teacher);
        $this->entityManager->flush();
        $this->assertWeight(0, 'the folder and both files in the corbeille');

        $this->manager->restore($folder, $this->teacher);
        $this->entityManager->flush();
        $this->assertWeight(4250, 'restored');

        $this->manager->trash($b, $this->teacher);
        $this->entityManager->flush();
        $this->manager->purgeNow($b);
        $this->entityManager->flush();
        $this->assertWeight(4000, 'b trashed then deleted for good - counted off once, not twice');
    }

    private function assertWeight(int $expected, string $step): void
    {
        self::assertSame($expected, static::getContainer()->get(FileLibraryQuota::class)->usedBytes($this->teacher), $step.' (in memory)');
        self::assertEquals($expected, $this->entityManager->getConnection()->fetchOne('SELECT file_library_used_bytes FROM `user` WHERE id = ?', [$this->teacher->getId()]), $step.' (stored)');

        $run = static::getContainer()->get(CounterRecomputer::class)->recompute(FileLibraryUsageCounter::NAME, (int) $this->teacher->getId(), dryRun: true);
        self::assertSame([], $run->drifts, $step.': the recomputation agrees');
    }
}
