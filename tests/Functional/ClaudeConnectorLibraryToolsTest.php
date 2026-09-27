<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlatformActivity;
use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Entity\SequenceTemplate;
use App\Enum\PlatformActivityType;
use App\Service\MixedExampleCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The quiz and séquence tools of the Claude connector, called the way Claude calls them. What is
 * pinned is what a teacher would check in MonCampus afterwards: the object exists, it is theirs, it
 * is where they asked, and a refused document left nothing behind.
 */
class ClaudeConnectorLibraryToolsTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    public function testTheToolsAreListed(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));

        $names = $this->toolNames($token);

        foreach (['library_list', 'format_guide', 'quiz_get', 'quiz_validate', 'quiz_create', 'quiz_add_questions', 'sequence_get', 'sequence_validate', 'sequence_create', 'sequence_add_seances', 'folder_create', 'quiz_link'] as $tool) {
            self::assertContains($tool, $names);
        }
    }

    public function testTheGuideIsTheOneTheAssistantShows(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));

        self::assertStringContainsString('moncampus-quiz/1', $this->callTool($token, 'format_guide', ['format' => 'quiz'])['text']);
        self::assertStringContainsString('moncampus-sequence/1', $this->callTool($token, 'format_guide', ['format' => 'sequence'])['text']);
        self::assertStringContainsString('moncampus-bareme/1', $this->callTool($token, 'format_guide', ['format' => 'bareme'])['text']);
    }

    public function testASequenceThenAQuizAttachedToItsFirstSeance(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $token = $this->accessTokenFor($teacher);

        $folder = $this->callTool($token, 'folder_create', ['library' => 'sequence', 'name' => 'SISR']);
        self::assertFalse($folder['isError'], $folder['text']);

        $sequence = $this->callTool($token, 'sequence_create', [
            'document' => json_decode((string) file_get_contents(__DIR__.'/../Fixtures/sequence-ansible.json'), true),
            'folderId' => $folder['data']['id'],
        ]);
        self::assertFalse($sequence['isError'], $sequence['text']);
        $seances = $sequence['data']['seances'];
        self::assertIsArray($seances);
        self::assertNotEmpty($seances);
        $firstSeance = $seances[0];
        self::assertIsArray($firstSeance);

        $quiz = $this->callTool($token, 'quiz_create', [
            'document' => json_decode((string) MixedExampleCatalog::json('reseaux'), true),
            'seanceId' => $firstSeance['id'],
        ]);
        self::assertFalse($quiz['isError'], $quiz['text']);

        $stored = $this->entityManager()->find(QuizTemplate::class, $quiz['data']['id']);
        self::assertInstanceOf(QuizTemplate::class, $stored);
        self::assertSame($teacher->getId(), $stored->getTeacher()?->getId());
        self::assertSame(\count($stored->getQuestions()), $quiz['data']['questionCount']);
        self::assertSame([$firstSeance['id']], array_map(static fn ($seance) => $seance->getId(), $stored->getSeanceTemplates()->toArray()));

        $storedSequence = $this->entityManager()->find(SequenceTemplate::class, $sequence['data']['id']);
        self::assertInstanceOf(SequenceTemplate::class, $storedSequence);
        self::assertSame($folder['data']['id'], $storedSequence->getFolder()?->getId());

        // Both creations are in the activity log, with the tool that made them.
        $logged = $this->entityManager()->getRepository(PlatformActivity::class)->findBy(['type' => PlatformActivityType::ClaudeConnectorContentCreated, 'actor' => $teacher]);
        self::assertCount(3, $logged);

        $list = $this->callTool($token, 'library_list', ['library' => 'sequence']);
        self::assertSame([$sequence['data']['id']], array_column((array) $list['data']['sequences'], 'id'));
    }

    public function testARefusedQuestionRefusesTheWholeDocument(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $token = $this->accessTokenFor($teacher);

        $result = $this->callTool($token, 'quiz_create', ['document' => [
            'format' => 'moncampus-quiz/1',
            'template' => ['name' => 'VLAN'],
            'questions' => [
                ['type' => 'qcm', 'label' => 'Un VLAN sépare…', 'difficulty' => 'facile', 'answers' => ['les domaines de diffusion', 'les câbles'], 'correct' => [1]],
                ['type' => 'inconnu', 'label' => '?'],
            ],
        ]]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('rien n\'a été créé', $result['text']);
        self::assertSame([], $this->entityManager()->getRepository(QuizTemplate::class)->findBy(['teacher' => $teacher]));
    }

    public function testAnImageReferenceIsRefused(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));

        $result = $this->callTool($token, 'quiz_validate', ['document' => [
            'format' => 'moncampus-quiz/1',
            'questions' => [['type' => 'qcm', 'label' => 'Quel schéma ?', 'difficulty' => 'facile', 'answers' => ['A', 'B'], 'correct' => [1], 'mediaRef' => 'schema.png']],
        ]]);

        self::assertFalse($result['data']['valid']);
        self::assertStringContainsString('mediaRef', (string) json_encode($result['data']['errors']));
    }

    public function testQuestionsAreAppendedNeverReplaced(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));
        $quiz = $this->callTool($token, 'quiz_create', ['document' => json_decode((string) MixedExampleCatalog::json('reseaux'), true)]);
        $before = $quiz['data']['questionCount'];
        self::assertIsInt($before);

        $added = $this->callTool($token, 'quiz_add_questions', [
            'quizId' => $quiz['data']['id'],
            'questions' => [['type' => 'vrai_faux', 'label' => 'Le trunk transporte plusieurs VLAN.', 'difficulty' => 'facile', 'correct' => true]],
        ]);

        self::assertFalse($added['isError'], $added['text']);
        self::assertSame($before + 1, $added['data']['questionCount']);
    }

    public function testSomebodyElsesQuizOrFolderIsNotFound(): void
    {
        $owner = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.owner');
        $theirs = new QuizTemplate($owner);
        $theirs->setName('Le leur');
        $theirs->setCreatedBy($owner);
        $theirFolder = new QuizFolder($owner, 'Le leur');
        $theirFolder->setCreatedBy($owner);
        $this->entityManager()->persist($theirs);
        $this->entityManager()->persist($theirFolder);
        $this->entityManager()->flush();

        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));

        $read = $this->callTool($token, 'quiz_get', ['quizId' => $theirs->getId()]);
        self::assertTrue($read['isError']);
        self::assertStringContainsString('introuvable', $read['text']);

        $filed = $this->callTool($token, 'quiz_create', [
            'document' => json_decode((string) MixedExampleCatalog::json('reseaux'), true),
            'folderId' => $theirFolder->getId(),
        ]);
        self::assertTrue($filed['isError']);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
