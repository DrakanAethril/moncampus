<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FileLibraryNode;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Enum\QuestionType;
use App\Service\QuizQuestionCompleteness;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pictures and modifications of a quiz through the Claude connector: a question names an image of
 * the teacher's file library (`imageFileId`) - the statement's, the « image » type's, the one a zone
 * question is placed on - and quiz_update rewrites a question in place, sets a picture, renames and
 * reorders, all checked before anything is written.
 */
class ClaudeConnectorQuizImagesTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    // A 1 x 1 PNG.
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testAQuizIsCreatedWithItsPicturesAndItsZonesOnAnImage(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.images'));
        $schema = $this->image($token, 'schema.png');

        $created = $this->callTool($token, 'quiz_create', ['document' => [
            'format' => 'moncampus-quiz/1',
            'template' => ['name' => 'Le réseau en images'],
            'questions' => [
                ['type' => 'image', 'label' => 'Quel équipement est entouré ?', 'difficulty' => 'facile', 'answers' => ['Un switch', 'Un routeur'], 'correct' => [2], 'imageFileId' => $schema],
                ['type' => 'zone', 'label' => 'Cliquez sur le routeur.', 'difficulty' => 'moyen', 'support' => ['kind' => 'image', 'zones' => [['id' => 'z1', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2], ['id' => 'z2', 'x' => 0.6, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2]]], 'correct' => ['z2'], 'imageFileId' => $schema],
                ['type' => 'legende', 'label' => 'Placez les noms.', 'difficulty' => 'moyen', 'support' => ['kind' => 'image', 'zones' => [['id' => 'z1', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2], ['id' => 'z2', 'x' => 0.6, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2]]], 'labels' => ['z1' => 'Switch', 'z2' => 'Routeur'], 'imageFileId' => $schema],
                ['type' => 'vrai_faux', 'label' => 'Un routeur relie deux réseaux.', 'difficulty' => 'facile', 'correct' => true],
            ],
        ]]);

        self::assertFalse($created['isError'], $created['text']);
        self::assertSame(0, $created['data']['questionsToComplete']);
        $questions = $this->questionsOf($created['data']['id']);
        $file = $this->node($schema);
        foreach ([0, 1, 2] as $index) {
            self::assertSame($file->getStorageKey(), $questions[$index]->getImageStorageKey(), 'the library file is linked, never copied');
            self::assertSame($file->getId(), $questions[$index]->getLibraryNode()?->getId());
        }
        self::assertSame(QuestionType::Zone, $questions[1]->getType());
        self::assertCount(2, $questions[1]->getImageZones());
        self::assertNull($questions[3]->getImageStorageKey());

        $read = $this->callTool($token, 'quiz_get', ['quizId' => $created['data']['id']]);
        self::assertStringNotContainsString('imageKey', $read['text']);
        $document = $read['data']['document'];
        self::assertIsArray($document);
        self::assertSame([1, 2, 3, 4], array_column($document['questions'], 'number'));
        self::assertSame([$schema, $schema, $schema], array_column($document['questions'], 'imageFileId'));
    }

    public function testAPictureThatIsNotOneOrIsNotThereRefusesTheDocument(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.images');
        $token = $this->accessTokenFor($teacher);
        $notes = $this->callTool($token, 'file_create', ['title' => 'Notes', 'content' => 'Texte.', 'format' => 'md']);
        $theirs = $this->image($this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.other')), 'leur.png');
        $mine = $this->image($token, 'schema.png');

        $result = $this->callTool($token, 'quiz_create', ['document' => [
            'format' => 'moncampus-quiz/1',
            'questions' => [
                ['type' => 'image', 'label' => 'Un texte ?', 'difficulty' => 'facile', 'answers' => ['A', 'B'], 'correct' => [1], 'imageFileId' => $notes['data']['fileId']],
                ['type' => 'image', 'label' => 'Le leur ?', 'difficulty' => 'facile', 'answers' => ['A', 'B'], 'correct' => [1], 'imageFileId' => $theirs],
                ['type' => 'zone', 'label' => 'Une zone de code sur une image ?', 'difficulty' => 'facile', 'support' => ['kind' => 'code', 'content' => '[[z1|a]] [[z2|b]]'], 'correct' => ['z1'], 'imageFileId' => $mine],
            ],
        ]]);

        self::assertTrue($result['isError']);
        $refusals = $result['text'];
        self::assertStringContainsString('Question 1', $refusals);
        self::assertStringContainsString('n\'est pas une image', $refusals);
        self::assertStringContainsString('Question 2', $refusals);
        self::assertStringContainsString('introuvable', $refusals);
        self::assertStringContainsString('Question 3', $refusals);
        self::assertSame([], $this->entityManager()->getRepository(QuizTemplate::class)->findBy(['teacher' => $teacher]));
    }

    public function testAQuizIsModifiedInPlace(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.images'));
        $created = $this->callTool($token, 'quiz_create', ['document' => [
            'format' => 'moncampus-quiz/1',
            'template' => ['name' => 'Brouillon', 'subject' => 'Réseau'],
            'questions' => [
                ['type' => 'qcm', 'label' => 'Ancienne question', 'difficulty' => 'facile', 'answers' => ['A', 'B'], 'correct' => [1]],
                ['type' => 'image', 'label' => 'Que montre le schéma ?', 'difficulty' => 'facile', 'answers' => ['Un switch', 'Un routeur'], 'correct' => [2], 'media' => ['name' => 'schema.png']],
                ['type' => 'vrai_faux', 'label' => 'Troisième.', 'difficulty' => 'facile', 'correct' => true],
            ],
        ]]);
        self::assertFalse($created['isError'], $created['text']);
        self::assertSame(1, $created['data']['questionsToComplete']);
        $quizId = $created['data']['id'];
        $before = $this->questionsOf($quizId);
        $ids = array_map(static fn (QuizQuestion $q): ?int => $q->getId(), $before);
        $schema = $this->image($token, 'schema.png');

        $updated = $this->callTool($token, 'quiz_update', [
            'quizId' => $quizId,
            'name' => 'Le réseau',
            'description' => 'Révisions.',
            'replace' => [['number' => 1, 'question' => ['type' => 'vrai_faux', 'label' => 'Un switch relie des machines d\'un même réseau.', 'difficulty' => 'moyen', 'correct' => true]]],
            'images' => [['number' => 2, 'imageFileId' => $schema]],
            'order' => [3, 1, 2],
        ]);

        self::assertFalse($updated['isError'], $updated['text']);
        self::assertSame(0, $updated['data']['questionsToComplete']);
        $quiz = $this->entityManager()->find(QuizTemplate::class, $quizId);
        self::assertInstanceOf(QuizTemplate::class, $quiz);
        self::assertSame('Le réseau', $quiz->getName());
        self::assertSame('Réseau', $quiz->getSubject(), 'a field not named is not written');
        self::assertSame('Révisions.', $quiz->getDescription());

        $after = $this->questionsOf($quizId);
        self::assertSame([$ids[2], $ids[0], $ids[1]], array_map(static fn (QuizQuestion $q): ?int => $q->getId(), $after), 'rewritten in place, then reordered');
        self::assertSame(QuestionType::VraiFaux, $after[1]->getType());
        self::assertSame('Un switch relie des machines d\'un même réseau.', $after[1]->getLabel());
        self::assertSame($this->node($schema)->getStorageKey(), $after[2]->getImageStorageKey());
        self::assertNull($after[2]->getExpectedMediaName());
        self::assertNull(static::getContainer()->get(QuizQuestionCompleteness::class)->gapOf($after[2]));
    }

    public function testAModificationWithOneMistakeWritesNothing(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.images'));
        $created = $this->callTool($token, 'quiz_create', ['document' => [
            'format' => 'moncampus-quiz/1',
            'template' => ['name' => 'Intact'],
            'questions' => [
                ['type' => 'vrai_faux', 'label' => 'Un.', 'difficulty' => 'facile', 'correct' => true],
                ['type' => 'vrai_faux', 'label' => 'Deux.', 'difficulty' => 'facile', 'correct' => false],
            ],
        ]]);
        $quizId = $created['data']['id'];

        $refused = $this->callTool($token, 'quiz_update', [
            'quizId' => $quizId,
            'name' => 'Renommé',
            'replace' => [['number' => 1, 'question' => ['type' => 'qcm', 'label' => 'Sans réponses', 'difficulty' => 'facile']]],
            'images' => [['number' => 5, 'imageFileId' => 1]],
            'order' => [1, 1],
        ]);

        self::assertTrue($refused['isError']);
        $details = $refused['text'];
        self::assertStringContainsString('replace, question 1', $details);
        self::assertStringContainsString('images : « number »', $details);
        self::assertStringContainsString('« order »', $details);
        $this->entityManager()->clear();
        $quiz = $this->entityManager()->find(QuizTemplate::class, $quizId);
        self::assertInstanceOf(QuizTemplate::class, $quiz);
        self::assertSame('Intact', $quiz->getName());
        self::assertSame(['Un.', 'Deux.'], array_map(static fn (QuizQuestion $q): ?string => $q->getLabel(), $this->questionsOf($quizId)));
    }

    public function testSomebodyElsesQuizIsNotModified(): void
    {
        $owner = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.owner');
        $theirs = new QuizTemplate($owner);
        $theirs->setName('Le leur');
        $theirs->setCreatedBy($owner);
        $this->entityManager()->persist($theirs);
        $this->entityManager()->flush();

        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.images'));
        $result = $this->callTool($token, 'quiz_update', ['quizId' => $theirs->getId(), 'name' => 'À moi']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('introuvable', $result['text']);
    }

    private function image(string $token, string $name): int
    {
        $uploaded = $this->callTool($token, 'file_upload', ['name' => $name, 'base64' => self::PNG]);
        self::assertFalse($uploaded['isError'], $uploaded['text']);
        self::assertIsInt($uploaded['data']['fileId']);

        return $uploaded['data']['fileId'];
    }

    /** @return list<QuizQuestion> */
    private function questionsOf(mixed $quizId): array
    {
        $this->entityManager()->clear();

        /* @var list<QuizQuestion> */
        return $this->entityManager()->getRepository(QuizQuestion::class)->findBy(['quizTemplate' => $quizId], ['orderIndex' => 'ASC']);
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
