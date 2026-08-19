<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FileLibraryNode;
use App\Entity\LibraryResource;
use App\Entity\SeancePhaseTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\FileLibraryNodeType;
use App\Enum\LibraryResourceSourceType;
use App\Service\ContentShareQuotaException;
use App\Service\SequenceDuplicator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Un partage donne à lire ; une duplication donne à posséder », pinned against a real database -
 * design/validated/content-sharing-between-teachers.md.
 *
 * Two things are asserted, and both are ways the feature could quietly stop being true:
 *
 * 1. **the folders and the second object.** The duplication creates
 *    `<Titre de la séquence>/<Titre de la séance>/`, every copied file gets a **fresh storage key**,
 *    and the author's own library is untouched - « un lien est une référence » holds *inside* one
 *    library, and here two are crossed;
 * 2. **the refusal is global.** One byte over the limit writes **nothing** - asserted on the node
 *    count *and* on the séquence count, since a partial write is precisely what looks like a
 *    success.
 *
 * The objects are real, and they are local: `config/packages/flysystem.yaml` points `uploads.storage`
 * at a directory under `var/cache` in the test environment, because the development one points at the
 * **real** S3 and a suite that writes into production's bucket is a suite nobody dares run. So the
 * copies asserted below are copies that actually happened.
 */
class ContentShareDuplicationTest extends FunctionalTestCase
{
    public function testDuplicationCreatesTheTwoFoldersAndASecondObject(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'dup.author');
        $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'dup.recipient');

        $sequence = $this->sequenceWithFiles('dup.author');
        $author = $this->user('dup.author');
        $recipient = $this->user('dup.recipient');

        $copy = static::getContainer()->get(SequenceDuplicator::class)->duplicate($sequence, $recipient, null);

        // The séquence itself is the recipient's, and its content came across.
        self::assertSame($recipient->getId(), $copy->getTeacher()?->getId());
        self::assertNotSame($sequence->getId(), $copy->getId());
        self::assertSame('Adressage IP', $copy->getTitre());
        // Asserted through a query, not through the copy's own collection: Doctrine does not push a
        // freshly persisted child into its parent's inverse side, and this codebase carries no adder
        // for it - a controller reads the séquence back on the next request.
        self::assertCount(1, $entityManager->getRepository(SeanceTemplate::class)->findBy(['sequenceTemplate' => $copy]));

        // The folder rule: one folder named after the séquence, one subfolder named after the séance.
        $folders = $this->nodesOf($recipient, FileLibraryNodeType::Folder);
        self::assertSame(['Adressage IP', 'Du besoin à l\'adresse'], array_map(static fn (FileLibraryNode $n): string => $n->getName(), $folders));
        self::assertNull($folders[0]->getParent());
        self::assertSame($folders[0]->getId(), $folders[1]->getParent()?->getId());

        // A real second object, with its own key - the author deleting theirs must never empty the
        // recipient's folder.
        $files = $this->nodesOf($recipient, FileLibraryNodeType::File);
        self::assertCount(2, $files);

        foreach ($files as $file) {
            self::assertNotSame('library-resources/source-cours.pdf', $file->getStorageKey());
            self::assertNotSame('library-resources/source-tp.pdf', $file->getStorageKey());
        }

        // The object really is there, and it really is a second one.
        $storage = static::getContainer()->get('uploads.storage');

        foreach ($files as $file) {
            self::assertTrue($storage->fileExists((string) $file->getStorageKey()));
        }

        self::assertTrue($storage->fileExists('library-resources/source-cours.pdf'), 'the author\'s own object is untouched');

        // The new resource rows point at the recipient's nodes and carry their own key, copied from
        // the node - the rule that leaves every reader of a file untouched.
        $resources = $entityManager->getRepository(LibraryResource::class)->findBy(['teacher' => $recipient]);
        self::assertCount(3, $resources, 'two uploads and the link');

        foreach ($resources as $resource) {
            if (LibraryResourceSourceType::Upload === $resource->getType()) {
                $node = $resource->getLibraryNode();
                self::assertNotNull($node);
                self::assertSame($node->getStorageKey(), $resource->getStorageKey());
            }
        }

        // And the author's own library is exactly as it was: nothing of theirs was moved or renamed.
        self::assertSame([], $this->nodesOf($author, FileLibraryNodeType::Folder));
        self::assertCount(1, $entityManager->getRepository(SeanceTemplate::class)->findBy(['sequenceTemplate' => $sequence]));
    }

    /**
     * One byte over the limit and **nothing** is written - not the séquence, not the folders, not
     * the first files that would have fitted. The lesson is the class import's, « un bloquant refuse
     * tout le fichier », and it applies verbatim.
     */
    public function testAQuotaRefusalWritesNothingAtAll(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'quota.author');
        $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'quota.recipient');

        $sequence = $this->sequenceWithFiles('quota.author');
        $recipient = $this->user('quota.recipient');

        // The duplication weighs 3 000 bytes (2 000 + 1 000); the recipient may hold 2 999.
        $recipient->setFileLibraryQuotaBytes(2999);
        $entityManager->flush();

        try {
            static::getContainer()->get(SequenceDuplicator::class)->duplicate($sequence, $recipient, null);
            self::fail('The duplication should have been refused.');
        } catch (ContentShareQuotaException $refusal) {
            self::assertSame(3000, $refusal->requiredBytes);
            self::assertSame(1, $refusal->shortfallBytes());
        }

        // Both counts, because a partial write is what looks like a success: no node, and no
        // séquence either.
        self::assertSame([], $entityManager->getRepository(FileLibraryNode::class)->findBy(['owner' => $recipient]));
        self::assertSame([], $entityManager->getRepository(SequenceTemplate::class)->findBy(['teacher' => $recipient]));
    }

    /**
     * A séquence with two supports on the séance's phase and the séquence itself, plus a link that
     * must weigh nothing.
     */
    private function sequenceWithFiles(string $authorUsername): SequenceTemplate
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $author = $this->user($authorUsername);

        $sequence = new SequenceTemplate($author);
        $sequence->setTitre('Adressage IP');
        $entityManager->persist($sequence);

        $seance = new SeanceTemplate($sequence);
        $seance->setTitre('Du besoin à l\'adresse');
        $seance->setOrdre(1);
        $entityManager->persist($seance);

        $phase = new SeancePhaseTemplate($seance);
        $phase->setNom('Synthèse');
        $phase->setOrdre(1);
        $entityManager->persist($phase);

        $this->upload($author, 'source-cours.pdf', 2000, static fn (LibraryResource $r): mixed => $r->setSequenceTemplate($sequence));
        // A phase's support lands in its séance's folder, not in a third level.
        $this->upload($author, 'source-tp.pdf', 1000, static fn (LibraryResource $r): mixed => $r->setSeancePhaseTemplate($phase));

        $link = new LibraryResource($author, 'Le RFC 1918');
        $link->setType(LibraryResourceSourceType::Link);
        $link->setUrl('https://www.rfc-editor.org/rfc/rfc1918');
        $link->setSequenceTemplate($sequence);
        $entityManager->persist($link);

        $entityManager->flush();

        // Read back rather than kept: Doctrine does not push a freshly persisted child into its
        // parent's inverse collection, so a séquence built in this unit of work would answer "no
        // séance, no support" to the duplicator. A controller always has a séquence loaded from the
        // database, and this is what that looks like.
        $id = $sequence->getId();
        $entityManager->clear();

        return $entityManager->getRepository(SequenceTemplate::class)->find($id) ?? self::fail('The fixture séquence disappeared.');
    }

    private function user(string $username): User
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]) ?? self::fail(\sprintf('No user "%s".', $username));
    }

    /** @param \Closure(LibraryResource): mixed $attach */
    private function upload(User $author, string $name, int $bytes, \Closure $attach): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        // Linked to a library node of the author's own, which is where the byte count comes from -
        // and which is what the copy must NOT be pointed at.
        $node = new FileLibraryNode($author, FileLibraryNodeType::File, $name);
        $node->setCreatedBy($author)
            ->setStorageKey('file-library/source-'.$name)
            ->setOriginalName($name)
            ->setMimeType('application/pdf')
            ->setSizeBytes($bytes);
        $entityManager->persist($node);

        $resource = new LibraryResource($author, $name);
        $resource->setType(LibraryResourceSourceType::Upload);
        $resource->setStorageKey('library-resources/'.$name);
        $resource->setLibraryNode($node);
        $attach($resource);
        $entityManager->persist($resource);

        // The object the copy will read. Without it the duplication would fail on a missing source,
        // which is a different test than this one.
        $storage = static::getContainer()->get('uploads.storage');
        $storage->write('library-resources/'.$name, str_repeat('x', $bytes));
    }

    /** @return list<FileLibraryNode> */
    private function nodesOf(User $owner, FileLibraryNodeType $type): array
    {
        /** @var list<FileLibraryNode> $nodes */
        $nodes = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(FileLibraryNode::class)
            ->findBy(['owner' => $owner, 'type' => $type], ['id' => 'ASC']);

        return $nodes;
    }
}
