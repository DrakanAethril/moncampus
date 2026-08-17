<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FileLibraryNode;
use App\Entity\Message;
use App\Entity\MessageAttachment;
use App\Entity\MessageThread;
use App\Entity\User;
use App\Enum\FileLibraryNodeType;
use App\Service\FileLibraryLinks;
use App\Service\FileLibraryNodeManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The rule the whole feature rests on, pinned against a real database:
 *
 * > **Un lien est une référence, pas une copie. Le fichier existe une fois, il pèse une fois, et le
 * > supprimer de la bibliothèque le retire partout où il était lié.**
 *
 * Three halves of it are asserted here, and each is a way the feature could quietly stop being true:
 *
 * 1. the linked row **keeps its own storage key** - which is what leaves every reader (Twig,
 *    `file_url()`, the mobile API, the PDF exports) untouched;
 * 2. the file **weighs once**: linking it eleven times does not change the quota;
 * 3. deleting it **removes the links**, and does so through the corbeille - the row goes now, the
 *    bytes go in thirty days.
 *
 * A functional test rather than a unit one because what is under test is nine foreign keys and a
 * `findBy`: mocking the entity manager would assert that the code calls what it calls.
 */
class FileLibraryLinkTest extends FunctionalTestCase
{
    public function testALinkedFileKeepsItsOwnStorageKeyAndWeighsOnce(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $quota = static::getContainer()->get(\App\Service\FileLibraryQuota::class);
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'link.teacher');
        $node = $this->libraryFile($teacher, 'support-vlan.pdf', 2048);

        $before = $quota->usedBytes($teacher);

        // Eleven links, as the design's own example puts it.
        for ($i = 0; $i < 11; ++$i) {
            $this->linkIntoAMessage($teacher, $node);
        }
        $entityManager->flush();

        // The row carries the node's key, copied - so nothing about *reading* the file changed.
        $attachment = $entityManager->getRepository(MessageAttachment::class)->findOneBy(['libraryNode' => $node]);
        self::assertNotNull($attachment);
        self::assertSame($node->getStorageKey(), $attachment->getStorageKey());

        // And the quota did not move: a file linked eleven times weighs once. Under a copy-on-link
        // model it would count something nobody can predict.
        self::assertSame($before, $quota->usedBytes($teacher));
    }

    public function testTheUsagePanelFindsEveryLinkAndDeletingRemovesThem(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $links = static::getContainer()->get(FileLibraryLinks::class);
        $manager = static::getContainer()->get(FileLibraryNodeManager::class);
        $quota = static::getContainer()->get(\App\Service\FileLibraryQuota::class);

        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'usage.teacher');
        $node = $this->libraryFile($teacher, 'cours-ipv4.pdf', 4096);
        $this->linkIntoAMessage($teacher, $node);
        $this->linkIntoAMessage($teacher, $node);
        $entityManager->flush();

        self::assertSame(2, $links->countUsagesOf($node));
        $usages = $links->usagesOf($node);
        self::assertSame('fileLibraryUsageMessageLabel', $usages[0]['where']);

        // « Supprimer partout »: the links go now.
        $manager->trash($node, $teacher);
        $entityManager->flush();

        self::assertSame(0, $links->countUsagesOf($node));
        self::assertCount(0, $entityManager->getRepository(MessageAttachment::class)->findBy(['libraryNode' => $node]));
        // The file is in the corbeille, not gone: its row is still there, and it has stopped counting
        // against the quota the moment it was deleted.
        self::assertTrue($node->isDeleted());
        self::assertSame(0, $quota->usedBytes($teacher));
    }

    private function libraryFile(User $owner, string $name, int $sizeBytes): FileLibraryNode
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $node = new FileLibraryNode($owner, FileLibraryNodeType::File, $name);
        $node->setCreatedBy($owner)
            ->setStorageKey('file-library/'.bin2hex(random_bytes(8)).'.pdf')
            ->setOriginalName($name)
            ->setMimeType('application/pdf')
            ->setSizeBytes($sizeBytes);

        $entityManager->persist($node);
        $entityManager->flush();

        return $node;
    }

    /** One link, made the way a controller makes it: the node's own key, plus the foreign key back. */
    private function linkIntoAMessage(User $sender, FileLibraryNode $node): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $thread = new MessageThread($sender);
        $thread->setSubject('Support de cours');
        $message = new Message($thread, $sender, 'Voici le support.');
        $attachment = (new MessageAttachment($message, (string) $node->getStorageKey(), $node->getName()))
            ->setLibraryNode($node);

        $entityManager->persist($thread);
        $entityManager->persist($message);
        $entityManager->persist($attachment);
    }
}
