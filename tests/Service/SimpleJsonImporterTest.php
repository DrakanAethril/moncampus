<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\BlankMode;
use App\Enum\QuestionType;
use App\Service\FileUploadService;
use App\Service\QuizCsvImporter;
use App\Service\QuizCsvImportException;
use App\Service\QuizImportImages;
use App\Service\SimpleJsonImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The JSON form of the five types that only ever arrived by CSV (QCM, QCM multiple, vrai/faux,
 * image, remise en ordre) plus texte à trous - what the mixed "moncampus-quiz/1" format needs in
 * order to cover the twelve types. Their answer semantics are the CSV's, deliberately: this reader
 * hands the very payload shape QuizCsvImporter produces to QuizCsvImporter::appendQuestions(), so
 * "bonnes"/"correct" cannot drift apart between the two channels.
 */
class SimpleJsonImporterTest extends TestCase
{
    private SimpleJsonImporter $importer;

    private QuizImportImages $images;

    protected function setUp(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                // The two labels a vrai/faux writes for itself are the only ones this reader
                // actually displays; everything else is an error key the test matches on.
                return match ($id) {
                    'answerTrueLabel' => 'Vrai',
                    'answerFalseLabel' => 'Faux',
                    default => $id.(isset($parameters['%number%']) ? ' #'.$parameters['%number%'] : ''),
                };
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };

        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        // A stub, not a mock: nothing here asserts on the bucket - what matters is that a resolved
        // reference lands on the question under its own key.
        $this->images = new QuizImportImages($requestStack, $this->createStub(FileUploadService::class));
        $this->importer = new SimpleJsonImporter($translator, new QuizCsvImporter($translator), $this->images);
    }

    public function testParseReadsTheFiveAnswerRowTypes(): void
    {
        $payload = $this->importer->parse($this->document([
            ['type' => 'qcm', 'label' => 'À quoi sert un VLAN ?', 'answers' => ['Segmenter', 'Router', 'Chiffrer'], 'correct' => [1], 'difficulty' => 'facile'],
            ['type' => 'qcm_multi', 'label' => 'Lesquels sont vrais ?', 'answers' => ['A', 'B', 'C'], 'correct' => [1, 3]],
            ['type' => 'vrai_faux', 'label' => 'Un port d’accès porte un seul VLAN.', 'correct' => [1]],
            ['type' => 'ordre', 'label' => 'Remettez en ordre.', 'answers' => ['Exécuter', 'Préparer', 'Lire'], 'correct' => [2, 1, 3]],
        ]));

        self::assertSame([], $payload['errors']);
        self::assertCount(4, $payload['questions']);
        self::assertSame('Réseaux', $payload['name']);

        // The correct index is 1-based, exactly like the CSV's "bonnes" column.
        self::assertSame(
            [['label' => 'Segmenter', 'correct' => true], ['label' => 'Router', 'correct' => false], ['label' => 'Chiffrer', 'correct' => false]],
            $payload['questions'][0]['answers'],
        );
        self::assertSame([true, false, true], array_column($payload['questions'][1]['answers'], 'correct'));

        // Vrai/faux writes its own two options when the document leaves them out.
        self::assertSame(['Vrai', 'Faux'], array_column($payload['questions'][2]['answers'], 'label'));
        self::assertTrue($payload['questions'][2]['answers'][0]['correct']);

        // An "ordre" question stores its options in the expected sequence, like the CSV reader.
        self::assertSame(['Préparer', 'Exécuter', 'Lire'], array_column($payload['questions'][3]['answers'], 'label'));
    }

    public function testVraiFauxAlsoAcceptsABoolean(): void
    {
        $payload = $this->importer->parse($this->document([
            ['type' => 'vrai_faux', 'label' => 'Affirmation.', 'correct' => false],
        ]));

        self::assertSame([], $payload['errors']);
        self::assertSame([false, true], array_column($payload['questions'][0]['answers'], 'correct'));
    }

    public function testParseReadsATexteATrous(): void
    {
        $payload = $this->importer->parse($this->document([
            [
                'type' => 'texte_a_trous',
                'label' => 'La méthode ... compile la requête, ... l’exécute.',
                'blanks' => [['prepare', 'prepare()'], ['execute']],
                'mode' => 'libre',
                'points' => 2,
            ],
        ]));

        self::assertSame([], $payload['errors']);
        self::assertSame([['prepare', 'prepare()'], ['execute']], $payload['questions'][0]['blanks']);
        self::assertSame(BlankMode::Libre->value, $payload['questions'][0]['blankMode']);
        self::assertSame(2.0, $payload['questions'][0]['points']);
    }

    /** One bad question costs its own line and nothing else - same rule as every other reader. */
    public function testABadQuestionIsReportedAndTheRestIsKept(): void
    {
        $payload = $this->importer->parse($this->document([
            ['type' => 'qcm', 'label' => 'Sans réponse', 'answers' => ['Seule'], 'correct' => [1]],
            ['type' => 'qcm', 'label' => 'Hors bornes', 'answers' => ['A', 'B'], 'correct' => [7]],
            ['type' => 'qcm', 'label' => 'Deux bonnes', 'answers' => ['A', 'B'], 'correct' => [1, 2]],
            ['type' => 'ordre', 'label' => 'Ordre partiel', 'answers' => ['A', 'B', 'C'], 'correct' => [1, 2]],
            ['type' => 'texte_a_trous', 'label' => 'Aucun trou ici', 'blanks' => [['x']]],
            ['type' => 'qcm', 'label' => 'Correcte', 'answers' => ['A', 'B'], 'correct' => [2]],
        ]));

        self::assertCount(1, $payload['questions']);
        self::assertCount(5, $payload['errors']);
        // Errors are numbered by the question's place in the document, not in the surviving list.
        self::assertStringContainsString('#1', $payload['errors'][0]);
        self::assertStringContainsString('#5', $payload['errors'][4]);
    }

    public function testAWholeDocumentCanBeUnusable(): void
    {
        $this->expectException(QuizCsvImportException::class);
        $this->importer->parse('{"format":"moncampus-simple/1","questions":[]}');
    }

    public function testTheFormatTagIsChecked(): void
    {
        $this->expectException(QuizCsvImportException::class);
        $this->importer->parse('{"format":"moncampus-zones/1","questions":[{"type":"qcm"}]}');
    }

    /** A deposited image is resolved to the object it already is: the question is complete at once. */
    public function testADepositedImageIsResolvedByItsReference(): void
    {
        $this->images->batchAdd('schema.png', 'quiz-import-images/aaa.png');

        $payload = $this->importer->parse($this->document([
            ['type' => 'image', 'label' => 'Quel équipement ?', 'answers' => ['Routeur', 'Commutateur'], 'correct' => [1], 'media' => ['ref' => 'img1']],
        ]));

        self::assertSame('img1', $payload['questions'][0]['mediaRef']);

        $template = new QuizTemplate(new User('teacher'));
        $this->importer->appendQuestions($template, $payload['questions']);

        $question = $this->firstQuestion($template);
        self::assertSame(QuestionType::Image, $question->getType());
        self::assertNotNull($question->getImageStorageKey());
        self::assertStringStartsWith('quiz-question-images/', $question->getImageStorageKey(), 'the batch object is copied, never shared - clearing the batch must not blank the question');
        self::assertNull($question->getExpectedMediaName());
    }

    /** The preview builds transient entities: it may show the deposited object, never copy it. */
    public function testThePreviewShowsTheDepositedObjectWithoutCopyingIt(): void
    {
        $this->images->batchAdd('schema.png', 'quiz-import-images/aaa.png');
        $payload = $this->importer->parse($this->document([
            ['type' => 'image', 'label' => 'Quel équipement ?', 'answers' => ['A', 'B'], 'correct' => [1], 'media' => ['ref' => 'img1']],
        ]));

        $template = new QuizTemplate(new User('teacher'));
        $this->importer->appendQuestions($template, $payload['questions'], copyImages: false);

        self::assertSame('quiz-import-images/aaa.png', $this->firstQuestion($template)->getImageStorageKey());
    }

    /**
     * A media the application does not hold is *not* an error: the question is created and waits for
     * its file. Confusing the two would make AI generation worthless as soon as an image is involved.
     */
    public function testANamedButUnknownMediaLeavesTheQuestionIncomplete(): void
    {
        $payload = $this->importer->parse($this->document([
            ['type' => 'image', 'label' => 'Q1', 'answers' => ['A', 'B'], 'correct' => [1], 'media' => ['name' => 'schema-reseau.png']],
            ['type' => 'image', 'label' => 'Q2', 'answers' => ['A', 'B'], 'correct' => [1], 'media' => ['ref' => 'img8']],
            ['type' => 'image', 'label' => 'Q3', 'answers' => ['A', 'B'], 'correct' => [1], 'media' => ['url' => 'https://exemple.test/p.png']],
        ]));

        self::assertSame([], $payload['errors'], 'an unresolved media is not an error');

        $template = new QuizTemplate(new User('teacher'));
        $this->importer->appendQuestions($template, $payload['questions']);

        $questions = $template->getQuestions()->toArray();
        self::assertSame('schema-reseau.png', $questions[0]->getExpectedMediaName());
        self::assertSame('img8', $questions[1]->getExpectedMediaName());
        self::assertSame('https://exemple.test/p.png', $questions[2]->getExpectedMediaName());
        self::assertNull($questions[0]->getImageStorageKey());
    }

    public function testHandlesAnswersForItsSixTypesOnly(): void
    {
        self::assertTrue($this->importer->handles(QuestionType::Qcm));
        self::assertTrue($this->importer->handles(QuestionType::Image));
        self::assertTrue($this->importer->handles(QuestionType::TexteATrous));
        self::assertFalse($this->importer->handles(QuestionType::Zone));
        self::assertFalse($this->importer->handles(QuestionType::Apparier));
    }

    public function testExportIsTheExactInverseOfImport(): void
    {
        $payload = $this->importer->parse($this->document([
            ['type' => 'qcm', 'label' => 'Q', 'answers' => ['A', 'B', 'C'], 'correct' => [3], 'difficulty' => 'difficile', 'explanation' => 'Parce que.'],
            ['type' => 'ordre', 'label' => 'O', 'answers' => ['Un', 'Deux', 'Trois'], 'correct' => [3, 1, 2]],
            ['type' => 'texte_a_trous', 'label' => 'Un ... ici', 'blanks' => [['mot']]],
        ]));

        $template = new QuizTemplate(new User('teacher'));
        $this->importer->appendQuestions($template, $payload['questions']);

        /** @var list<array<string, mixed>> $exported */
        $exported = $this->importer->export($template)['questions'];
        self::assertSame('qcm', $exported[0]['type']);
        self::assertSame(['A', 'B', 'C'], $exported[0]['answers']);
        self::assertSame([3], $exported[0]['correct']);
        self::assertSame('Parce que.', $exported[0]['explanation']);
        // A re-imported export must ask the same question: an "ordre" is stored sorted, so its
        // exported sequence is the identity, not the shuffle the document arrived with.
        self::assertSame(['Trois', 'Un', 'Deux'], $exported[1]['answers']);
        self::assertSame([1, 2, 3], $exported[1]['correct']);
        self::assertSame([['mot']], $exported[2]['blanks']);
    }

    /** @param list<array<string, mixed>> $questions */
    private function document(array $questions): string
    {
        return (string) json_encode([
            'format' => 'moncampus-simple/1',
            'template' => ['name' => 'Réseaux', 'subject' => 'SISR'],
            'questions' => $questions,
        ]);
    }

    private function firstQuestion(QuizTemplate $template): QuizQuestion
    {
        $question = $template->getQuestions()->first();
        self::assertInstanceOf(QuizQuestion::class, $question);

        return $question;
    }
}
