<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Entity\VideoResource;
use App\Entity\VideoResourceFile;
use App\Enum\FileLibraryNodeType;
use App\Service\FileLibraryNodeManager;
use App\Service\FileLibraryQuota;
use App\Service\FileLibraryVideoFork;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one exception of the link model, and both of its triggers
 * (design/validated/file-library.md, "The one exception: vidéo suivie, and it is deferred").
 *
 * What is asserted is what the design argues for, and what a reader would otherwise have to take on
 * trust:
 *
 * - **replacing** a node a vidéo suivie is built on leaves the work on the *old* object - students
 *   stay measured against the cut they watched - while the library serves the new one;
 * - **deleting** one leaves the work on a copy of it, so playback survives a file its teacher removed;
 * - in both cases `storage_key` stays non-null, which is what keeps "media deleted" out of the
 *   player, the statistics screen and the mobile API;
 * - and the forked copy is **not charged to the quota**: it is an artefact of the work, not a file
 *   the teacher stores.
 *
 * S3 is not reached: the fork's copy goes through App\Service\FileUploadService, stubbed here. What is
 * under test is which key each row ends up carrying, which is the whole of the rule.
 */
class FileLibraryVideoForkTest extends FunctionalTestCase
{
    public function testReplacingKeepsTheWorkOnTheObjectItsStudentsWatched(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $manager = static::getContainer()->get(FileLibraryNodeManager::class);

        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'fork.teacher');
        $node = $this->libraryVideo($teacher, 'seance-1.mp4');
        $originalKey = (string) $node->getStorageKey();
        $file = $this->videoBuiltOn($node, $teacher);
        $entityManager->flush();

        $manager->replace($node, 'file-library/nouvelle-version.mp4', 'seance-1.mp4', 'video/mp4', 4096, $teacher);
        $entityManager->flush();

        // The library serves the new object...
        self::assertSame('file-library/nouvelle-version.mp4', $node->getStorageKey());
        // ...and the work still points at the cut its students were measured against. 62 % of another
        // cut is not 62 %, and a cue point at 04:32 would no longer point at anything.
        self::assertSame($originalKey, $file->getStorageKey());
        // The row has stopped being a link: the work owns that object now, so the library must never
        // schedule its removal.
        self::assertNull($file->getLibraryNode());
    }

    public function testDeletingLeavesTheWorkOnACopyThatCostsTheTeacherNothing(): void
    {
        // The copy is a real S3 CopyObject in the application; here what matters is *which key* the
        // row ends up carrying, so the writer is swapped for a stub. Done before the manager is
        // pulled out of the container, which is what builds the graph that uses it.
        static::getContainer()->set(FileUploadService::class, $this->createStub(FileUploadService::class));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $manager = static::getContainer()->get(FileLibraryNodeManager::class);
        $quota = static::getContainer()->get(FileLibraryQuota::class);

        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'fork.teacher2');
        $node = $this->libraryVideo($teacher, 'seance-2.mp4');
        $originalKey = (string) $node->getStorageKey();
        $file = $this->videoBuiltOn($node, $teacher);
        $entityManager->flush();

        $manager->trash($node, $teacher);
        $entityManager->flush();

        // A key the work owns, outside the library's prefix - and non-null, which is what keeps a
        // "media deleted" state out of the player and the mobile API.
        self::assertNotSame($originalKey, $file->getStorageKey());
        self::assertStringStartsWith(FileLibraryVideoFork::PREFIX, $file->getStorageKey());
        self::assertNull($file->getLibraryNode());

        // The file is in the corbeille and has stopped counting; the forked copy is charged to
        // nobody, since charging a teacher for a file they just deleted would be indefensible.
        self::assertTrue($node->isDeleted());
        self::assertSame(0, $quota->usedBytes($teacher));
    }

    private function libraryVideo(User $owner, string $name): FileLibraryNode
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $node = new FileLibraryNode($owner, FileLibraryNodeType::File, $name);
        $node->setCreatedBy($owner)
            ->setStorageKey('file-library/'.bin2hex(random_bytes(8)).'.mp4')
            ->setOriginalName($name)
            ->setMimeType('video/mp4')
            ->setSizeBytes(4096)
            ->setDurationSeconds(320);

        $entityManager->persist($node);
        $entityManager->flush();

        return $node;
    }

    /** A vidéo suivie referencing the library object, exactly as the back door creates it. */
    private function videoBuiltOn(FileLibraryNode $node, User $teacher): VideoResourceFile
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $program = $this->createProgram([], [$teacher], $teacher);

        $resource = new VideoResource($program, $teacher);
        $resource->setName('Séance filmée');

        $file = new VideoResourceFile((string) $node->getStorageKey(), 1);
        $file->setOriginalName($node->getName())
            ->setFileSize($node->getSizeBytes() ?? 0)
            ->setDurationSeconds($node->getDurationSeconds() ?? 0)
            ->setUploadedBy($teacher)
            ->setLibraryNode($node);

        $resource->addFile($file);
        $entityManager->persist($resource);
        $entityManager->persist($file);

        return $file;
    }
}
