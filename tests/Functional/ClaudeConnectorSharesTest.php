<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ContentShare;
use App\Entity\FileLibraryNode;
use App\Entity\Group;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Service\MixedExampleCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The sharing tools of the Claude connector, called the way Claude calls them: one teacher shares,
 * a colleague finds it among what they received and duplicates it into their own library, filed
 * where they asked - and a withdrawn share is read by nobody but its author.
 */
class ClaudeConnectorSharesTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    public function testTheToolsAreListed(): void
    {
        $names = $this->toolNames($this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude')));

        foreach (['share_recipients', 'share_create', 'share_list', 'share_duplicate', 'share_revoke'] as $tool) {
            self::assertContains($tool, $names);
        }
    }

    public function testAQuizSharedByNameIsDuplicatedIntoTheColleaguesFolder(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.auteur');
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.collegue');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'prof.etudiant');
        $authorToken = $this->accessTokenFor($author);
        $colleagueToken = $this->accessTokenFor($colleague);

        $quizId = $this->quiz($authorToken);

        // A student is never offered as a recipient.
        $found = $this->callTool($authorToken, 'share_recipients', ['query' => 'prof.']);
        self::assertFalse($found['isError'], $found['text']);
        $offered = array_column((array) $found['data']['colleagues'], 'userId');
        self::assertContains($colleague->getId(), $offered);
        self::assertNotContains($student->getId(), $offered);
        self::assertNotContains($author->getId(), $offered);

        $shared = $this->callTool($authorToken, 'share_create', [
            'type' => 'quiz',
            'id' => $quizId,
            'scope' => 'users',
            'userIds' => [$colleague->getId()],
            'note' => 'Pour ta classe de SIO 1.',
        ]);
        self::assertFalse($shared['isError'], $shared['text']);
        $shareId = $shared['data']['shareId'];
        self::assertIsInt($shareId);

        $received = $this->callTool($colleagueToken, 'share_list', ['box' => 'received']);
        self::assertFalse($received['isError'], $received['text']);
        $rows = (array) $received['data']['shares'];
        self::assertCount(1, $rows);
        self::assertIsArray($rows[0]);
        self::assertSame($shareId, $rows[0]['shareId']);
        self::assertSame('Pour ta classe de SIO 1.', $rows[0]['note']);
        self::assertArrayNotHasKey('audience', $rows[0], 'A reader is never told who else received it.');

        // A shared quiz is read before it is taken.
        self::assertFalse($this->callTool($colleagueToken, 'quiz_get', ['quizId' => $quizId])['isError']);

        $folderId = $this->folder($colleagueToken, 'quiz', 'Reçus de collègues');
        $copy = $this->callTool($colleagueToken, 'share_duplicate', ['shareId' => $shareId, 'folderId' => $folderId]);
        self::assertFalse($copy['isError'], $copy['text']);

        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(QuizTemplate::class, $copy['data']['id']);
        self::assertInstanceOf(QuizTemplate::class, $stored);
        self::assertSame($colleague->getId(), $stored->getTeacher()?->getId());
        self::assertSame($folderId, $stored->getFolder()?->getId());
        self::assertNotSame($quizId, $stored->getId());
        self::assertCount($this->quizEntity($quizId)->getQuestions()->count(), $stored->getQuestions());

        // The author reads who has it and how often it was taken.
        $mine = $this->callTool($authorToken, 'share_list', ['box' => 'mine']);
        $mineRow = ((array) $mine['data']['shares'])[0];
        self::assertIsArray($mineRow);
        self::assertSame(1, $mineRow['duplicationCount']);
        self::assertSame(['users' => ['Prof.collegue Test']], $mineRow['audience']);
    }

    public function testAWithdrawnShareIsNoLongerDuplicatedAndCanBeRestored(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.auteur');
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.collegue');
        $authorToken = $this->accessTokenFor($author);
        $colleagueToken = $this->accessTokenFor($colleague);

        $shareId = $this->callTool($authorToken, 'share_create', [
            'type' => 'quiz', 'id' => $this->quiz($authorToken), 'scope' => 'users', 'userIds' => [$colleague->getId()],
        ])['data']['shareId'];

        $revoked = $this->callTool($authorToken, 'share_revoke', ['shareId' => $shareId]);
        self::assertFalse($revoked['isError'], $revoked['text']);
        self::assertTrue($revoked['data']['revoked']);

        $refused = $this->callTool($colleagueToken, 'share_duplicate', ['shareId' => $shareId]);
        self::assertTrue($refused['isError']);
        self::assertStringContainsString('introuvable', $refused['text']);

        // The colleague cannot withdraw somebody else's share either.
        self::assertTrue($this->callTool($colleagueToken, 'share_revoke', ['shareId' => $shareId, 'restore' => true])['isError']);

        self::assertFalse($this->callTool($authorToken, 'share_revoke', ['shareId' => $shareId, 'restore' => true])['isError']);
        self::assertFalse($this->callTool($colleagueToken, 'share_duplicate', ['shareId' => $shareId])['isError']);
    }

    public function testOnlyTheAuthorShares(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.auteur');
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.collegue');
        $quizId = $this->quiz($this->accessTokenFor($author));

        $refused = $this->callTool($this->accessTokenFor($colleague), 'share_create', [
            'type' => 'quiz', 'id' => $quizId, 'scope' => 'catalog',
        ]);

        self::assertTrue($refused['isError']);
        self::assertSame(0, $this->entityManager()->getRepository(ContentShare::class)->count([]));
    }

    public function testAShareThatNamesOnlyAStudentIsRefused(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.auteur');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'eleve');
        $token = $this->accessTokenFor($author);

        $refused = $this->callTool($token, 'share_create', [
            'type' => 'quiz', 'id' => $this->quiz($token), 'scope' => 'users', 'userIds' => [$student->getId()],
        ]);

        self::assertTrue($refused['isError']);
        self::assertSame(0, $this->entityManager()->getRepository(ContentShare::class)->count([]));
    }

    public function testASequenceSharedToATeamIsPlannedThenDuplicatedIntoAFolder(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.auteur');
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_TEAM_SIO'], 'prof.collegue');
        $outsider = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.ailleurs');
        $team = new Group('Équipe SIO', 'ROLE_TEAM_SIO');
        $team->setCreatedBy($author);
        $this->entityManager()->persist($team);
        $this->entityManager()->flush();

        $authorToken = $this->accessTokenFor($author);
        $colleagueToken = $this->accessTokenFor($colleague);
        $outsiderToken = $this->accessTokenFor($outsider);
        $sequenceId = $this->sequence($authorToken);

        $groups = (array) $this->callTool($authorToken, 'share_recipients', ['groups' => true])['data']['groups'];
        $teamRow = array_values(array_filter($groups, static fn ($row) => \is_array($row) && $row['groupId'] === $team->getId()))[0] ?? null;
        self::assertIsArray($teamRow);
        self::assertSame(1, $teamRow['readerCount']);

        $shared = $this->callTool($authorToken, 'share_create', ['type' => 'sequence', 'id' => $sequenceId, 'scope' => 'group', 'groupIds' => [$team->getId()]]);
        self::assertFalse($shared['isError'], $shared['text']);
        $audience = $shared['data']['audience'];
        self::assertIsArray($audience);
        self::assertSame(1, $audience['readerCount'] ?? null);
        $shareId = $shared['data']['shareId'];

        self::assertSame([], $this->callTool($outsiderToken, 'share_list', ['box' => 'received'])['data']['shares']);

        $plan = $this->callTool($colleagueToken, 'share_duplicate', ['shareId' => $shareId, 'dryRun' => true]);
        self::assertFalse($plan['isError'], $plan['text']);
        self::assertTrue($plan['data']['dryRun']);
        self::assertTrue($plan['data']['fits']);
        self::assertSame(0, $this->entityManager()->getRepository(SequenceTemplate::class)->count(['teacher' => $colleague]), 'A dry run writes nothing.');

        $folderId = $this->folder($colleagueToken, 'sequence', 'SISR');
        $copy = $this->callTool($colleagueToken, 'share_duplicate', ['shareId' => $shareId, 'folderId' => $folderId]);
        self::assertFalse($copy['isError'], $copy['text']);

        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(SequenceTemplate::class, $copy['data']['id']);
        self::assertInstanceOf(SequenceTemplate::class, $stored);
        self::assertSame($colleague->getId(), $stored->getTeacher()?->getId());
        self::assertSame($folderId, $stored->getFolder()?->getId());
        self::assertCount($this->sequenceEntity($sequenceId)->getSeanceTemplates()->count(), $stored->getSeanceTemplates());
    }

    public function testALoneSeanceJoinsTheColleaguesSequenceAndNeverSomebodyElses(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.auteur');
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.collegue');
        $authorToken = $this->accessTokenFor($author);
        $colleagueToken = $this->accessTokenFor($colleague);

        $authorSequence = $this->sequenceEntity($this->sequence($authorToken));
        $seance = $authorSequence->getSeanceTemplates()->first();
        self::assertInstanceOf(SeanceTemplate::class, $seance);
        $shareId = $this->callTool($authorToken, 'share_create', ['type' => 'seance', 'id' => $seance->getId(), 'scope' => 'catalog'])['data']['shareId'];

        self::assertCount(1, (array) $this->callTool($colleagueToken, 'share_list', ['box' => 'catalog', 'type' => 'seance'])['data']['shares']);

        // The author's séquence is not the colleague's to write into.
        $refused = $this->callTool($colleagueToken, 'share_duplicate', ['shareId' => $shareId, 'sequenceId' => $authorSequence->getId()]);
        self::assertTrue($refused['isError']);

        $target = $this->sequenceEntity($this->sequence($colleagueToken));
        $before = $target->getSeanceTemplates()->count();

        $copy = $this->callTool($colleagueToken, 'share_duplicate', ['shareId' => $shareId, 'sequenceId' => $target->getId()]);
        self::assertFalse($copy['isError'], $copy['text']);
        self::assertSame($target->getId(), $copy['data']['sequenceId']);
        self::assertFalse($copy['data']['newSequence']);

        $this->entityManager()->clear();
        self::assertCount($before + 1, $this->sequenceEntity((int) $target->getId())->getSeanceTemplates());
    }

    public function testASharedFolderOfFilesIsCopiedWithItsContent(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.auteur');
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.collegue');
        $authorToken = $this->accessTokenFor($author);
        $colleagueToken = $this->accessTokenFor($colleague);

        $cours = $this->folder($authorToken, 'file', 'Cours réseau');
        $this->file($authorToken, 'Énoncé', $cours);
        $this->file($authorToken, 'Corrigé', $cours);
        $shareId = $this->callTool($authorToken, 'share_create', ['type' => 'file', 'id' => $cours, 'scope' => 'users', 'userIds' => [$colleague->getId()]])['data']['shareId'];

        $plan = $this->callTool($colleagueToken, 'share_duplicate', ['shareId' => $shareId, 'dryRun' => true]);
        self::assertFalse($plan['isError'], $plan['text']);
        self::assertSame(2, $plan['data']['fileCount']);

        $archives = $this->folder($colleagueToken, 'file', 'Archives');
        $copy = $this->callTool($colleagueToken, 'share_duplicate', ['shareId' => $shareId, 'folderId' => $archives]);
        self::assertFalse($copy['isError'], $copy['text']);
        self::assertSame('file_folder', $copy['data']['type']);

        $this->entityManager()->clear();
        $copied = $this->entityManager()->find(FileLibraryNode::class, $copy['data']['id']);
        self::assertInstanceOf(FileLibraryNode::class, $copied);
        self::assertSame($colleague->getId(), $copied->getOwner()->getId());
        self::assertSame($archives, $copied->getParent()?->getId());
        self::assertCount(2, $this->entityManager()->getRepository(FileLibraryNode::class)->findBy(['owner' => $colleague, 'parent' => $copied]));

        // A file of somebody else's library is not shared by the colleague.
        self::assertTrue($this->callTool($colleagueToken, 'share_create', ['type' => 'file', 'id' => $cours, 'scope' => 'catalog'])['isError']);
    }

    public function testSequencesAndASequenceFolderAreMovedNotCopied(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $token = $this->accessTokenFor($teacher);

        $sisr = $this->folder($token, 'sequence', 'SISR');
        $reseau = $this->folder($token, 'sequence', 'Réseau');
        $sequenceId = $this->sequence($token);

        $moved = $this->callTool($token, 'library_move', [
            'library' => 'sequence',
            'sequenceIds' => [$sequenceId],
            'folderIds' => [$reseau],
            'targetFolderId' => $sisr,
        ]);
        self::assertFalse($moved['isError'], $moved['text']);

        $this->entityManager()->clear();
        self::assertSame($sisr, $this->sequenceEntity($sequenceId)->getFolder()?->getId());
        self::assertCount(1, $this->entityManager()->getRepository(SequenceTemplate::class)->findBy(['teacher' => $teacher]));

        $intoItself = $this->callTool($token, 'library_move', ['library' => 'sequence', 'folderIds' => [$sisr], 'targetFolderId' => $reseau]);
        self::assertTrue($intoItself['isError']);
        self::assertStringContainsString('SISR', $intoItself['text']);
    }

    private function quiz(string $token): int
    {
        $created = $this->callTool($token, 'quiz_create', ['document' => json_decode((string) MixedExampleCatalog::json('reseaux'), true)]);
        self::assertFalse($created['isError'], $created['text']);
        self::assertIsInt($created['data']['id']);

        return $created['data']['id'];
    }

    private function sequence(string $token): int
    {
        $created = $this->callTool($token, 'sequence_create', [
            'document' => json_decode((string) file_get_contents(__DIR__.'/../Fixtures/sequence-ansible.json'), true),
        ]);
        self::assertFalse($created['isError'], $created['text']);
        self::assertIsInt($created['data']['id']);

        return $created['data']['id'];
    }

    private function folder(string $token, string $library, string $name): int
    {
        $created = $this->callTool($token, 'folder_create', ['library' => $library, 'name' => $name]);
        self::assertFalse($created['isError'], $created['text']);
        self::assertIsInt($created['data']['id']);

        return $created['data']['id'];
    }

    private function file(string $token, string $title, int $folderId): int
    {
        $created = $this->callTool($token, 'file_create', ['title' => $title, 'content' => 'Contenu.', 'format' => 'md', 'folderId' => $folderId]);
        self::assertFalse($created['isError'], $created['text']);
        self::assertIsInt($created['data']['fileId']);

        return $created['data']['fileId'];
    }

    private function quizEntity(int $id): QuizTemplate
    {
        $quiz = $this->entityManager()->find(QuizTemplate::class, $id);
        self::assertInstanceOf(QuizTemplate::class, $quiz);

        return $quiz;
    }

    private function sequenceEntity(int $id): SequenceTemplate
    {
        $sequence = $this->entityManager()->find(SequenceTemplate::class, $id);
        self::assertInstanceOf(SequenceTemplate::class, $sequence);

        return $sequence;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
