<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FileLibraryNode;
use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Service\MixedExampleCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `library_move`, called the way Claude calls it: quizzes, files and whole folders filed elsewhere
 * in their own library - moved, never copied - with the sub-folders following their folder.
 */
class ClaudeConnectorLibraryMoveTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    public function testAQuizAndAFolderWithItsSubFolderAreMovedNotCopied(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $token = $this->accessTokenFor($teacher);

        $reseaux = $this->folder($token, 'quiz', 'Réseaux');
        $vlan = $this->folder($token, 'quiz', 'VLAN', $reseaux);
        $sisr = $this->folder($token, 'quiz', 'SISR');
        $inVlan = $this->quiz($token, $vlan);
        $atRoot = $this->quiz($token, null);

        $moved = $this->callTool($token, 'library_move', [
            'library' => 'quiz',
            'quizIds' => [$atRoot],
            'folderIds' => [$reseaux],
            'targetFolderId' => $sisr,
        ]);
        self::assertFalse($moved['isError'], $moved['text']);

        $this->entityManager()->clear();
        self::assertSame($sisr, $this->quizFolder($reseaux)->getParent()?->getId());
        self::assertSame(\sprintf('/%d/%d/', $sisr, $reseaux), $this->quizFolder($vlan)->getPath());
        self::assertSame(2, $this->quizFolder($vlan)->getDepth());
        self::assertSame($vlan, $this->entityManager()->find(QuizTemplate::class, $inVlan)?->getFolder()?->getId());
        self::assertSame($sisr, $this->entityManager()->find(QuizTemplate::class, $atRoot)?->getFolder()?->getId());
        self::assertCount(2, $this->entityManager()->getRepository(QuizTemplate::class)->findBy(['teacher' => $teacher]));
        self::assertCount(3, $this->entityManager()->getRepository(QuizFolder::class)->findBy(['owner' => $teacher]));

        // Back at the root, explicitly.
        $back = $this->callTool($token, 'library_move', ['library' => 'quiz', 'quizIds' => [$atRoot], 'targetFolderId' => null]);
        self::assertFalse($back['isError'], $back['text']);
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(QuizTemplate::class, $atRoot)?->getFolder());
    }

    public function testAFolderIntoItsOwnSubFolderIsRefusedAndNothingMoves(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));
        $reseaux = $this->folder($token, 'quiz', 'Réseaux');
        $vlan = $this->folder($token, 'quiz', 'VLAN', $reseaux);
        $quiz = $this->quiz($token, null);

        $refused = $this->callTool($token, 'library_move', [
            'library' => 'quiz',
            'quizIds' => [$quiz],
            'folderIds' => [$reseaux],
            'targetFolderId' => $vlan,
        ]);

        self::assertTrue($refused['isError']);
        self::assertStringContainsString('Réseaux', $refused['text']);
        $this->entityManager()->clear();
        self::assertNull($this->quizFolder($reseaux)->getParent());
        self::assertNull($this->entityManager()->find(QuizTemplate::class, $quiz)?->getFolder());
    }

    public function testTheTargetMustBeNamed(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));
        $quiz = $this->quiz($token, $this->folder($token, 'quiz', 'Réseaux'));

        $refused = $this->callTool($token, 'library_move', ['library' => 'quiz', 'quizIds' => [$quiz]]);

        self::assertTrue($refused['isError']);
        self::assertStringContainsString('targetFolderId', $refused['text']);
    }

    public function testSomebodyElsesQuizIsNotFound(): void
    {
        $owner = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.owner');
        $theirs = new QuizTemplate($owner);
        $theirs->setName('Le leur');
        $theirs->setCreatedBy($owner);
        $this->entityManager()->persist($theirs);
        $this->entityManager()->flush();

        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));
        $mine = $this->folder($token, 'quiz', 'Le mien');

        $refused = $this->callTool($token, 'library_move', ['library' => 'quiz', 'quizIds' => [$theirs->getId()], 'targetFolderId' => $mine]);

        self::assertTrue($refused['isError']);
        self::assertStringContainsString('introuvable', $refused['text']);
    }

    public function testFilesAndAFolderOfTheFileLibraryAreMoved(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));

        $cours = $this->folder($token, 'file', 'Cours');
        $tp = $this->folder($token, 'file', 'TP', $cours);
        $archives = $this->folder($token, 'file', 'Archives');
        $inTp = $this->file($token, 'Énoncé', $tp);
        $first = $this->file($token, 'Notes', null);
        $second = $this->file($token, 'Notes', $cours);

        $moved = $this->callTool($token, 'library_move', [
            'library' => 'file',
            'fileIds' => [$first, $second],
            'folderIds' => [$cours],
            'targetFolderId' => $archives,
        ]);
        self::assertFalse($moved['isError'], $moved['text']);

        $this->entityManager()->clear();
        self::assertSame($archives, $this->node($cours)->getParent()?->getId());
        self::assertSame(\sprintf('/%d/%d/', $archives, $cours), $this->node($tp)->getPath());
        self::assertSame(\sprintf('/%d/%d/%d/', $archives, $cours, $tp), $this->node($inTp)->getPath());
        self::assertSame($archives, $this->node($first)->getParent()?->getId());
        self::assertSame($archives, $this->node($second)->getParent()?->getId());

        // Two files of the same name in one folder: the second is numbered, as on the screen.
        self::assertNotSame($this->node($first)->getName(), $this->node($second)->getName());
        self::assertSame([$first, $second], array_column(array_filter((array) $moved['data']['moved'], static fn ($row) => \is_array($row) && 'file' === $row['kind']), 'id'));
    }

    public function testAQuizIdIsNotAFileOfTheFileLibrary(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));
        $quiz = $this->quiz($token, null);

        $refused = $this->callTool($token, 'library_move', ['library' => 'file', 'quizIds' => [$quiz], 'targetFolderId' => null]);

        self::assertTrue($refused['isError']);
    }

    private function folder(string $token, string $library, string $name, ?int $parentId = null): int
    {
        $created = $this->callTool($token, 'folder_create', array_filter(['library' => $library, 'name' => $name, 'parentId' => $parentId]));
        self::assertFalse($created['isError'], $created['text']);
        self::assertIsInt($created['data']['id']);

        return $created['data']['id'];
    }

    private function quiz(string $token, ?int $folderId): int
    {
        $created = $this->callTool($token, 'quiz_create', array_filter([
            'document' => json_decode((string) MixedExampleCatalog::json('reseaux'), true),
            'folderId' => $folderId,
        ]));
        self::assertFalse($created['isError'], $created['text']);
        self::assertIsInt($created['data']['id']);

        return $created['data']['id'];
    }

    private function file(string $token, string $title, ?int $folderId): int
    {
        $created = $this->callTool($token, 'file_create', array_filter(['title' => $title, 'content' => 'Contenu.', 'format' => 'md', 'folderId' => $folderId]));
        self::assertFalse($created['isError'], $created['text']);
        self::assertIsInt($created['data']['fileId']);

        return $created['data']['fileId'];
    }

    private function quizFolder(int $id): QuizFolder
    {
        $folder = $this->entityManager()->find(QuizFolder::class, $id);
        self::assertInstanceOf(QuizFolder::class, $folder);

        return $folder;
    }

    private function node(int $id): FileLibraryNode
    {
        $node = $this->entityManager()->find(FileLibraryNode::class, $id);
        self::assertInstanceOf(FileLibraryNode::class, $node);

        return $node;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
