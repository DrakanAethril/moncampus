<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FileUploadService;
use App\Service\InteractiveQuizImporterRegistry;
use App\Service\JsonDocumentSplitter;
use App\Service\MatchingImageStore;
use App\Service\MatchingJsonImporter;
use App\Service\MixedJsonImporter;
use App\Service\NumericJsonImporter;
use App\Service\QuizCsvImporter;
use App\Service\QuizCsvImportException;
use App\Service\QuizImportBatchReader;
use App\Service\QuizImportImages;
use App\Service\ShortAnswerJsonImporter;
use App\Service\SimpleJsonImporter;
use App\Service\ZoneJsonImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reading a whole batch: several documents in, one payload per quiz out.
 *
 * The rule this pins down is what happens to a document the readers refuse outright. It refuses the
 * batch, naming its rank - the same answer the alternance contract import gives a blocking row, and
 * for the same reason: a paste of five quizzes that silently becomes four is a loss the teacher has
 * no way to notice. Per-question problems keep the behaviour they have always had, which is to be
 * listed on the verification screen and skipped.
 */
class QuizImportBatchReaderTest extends TestCase
{
    private QuizImportBatchReader $reader;

    protected function setUp(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
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

        $uploads = $this->createStub(FileUploadService::class);
        $mixed = new MixedJsonImporter(
            $translator,
            new SimpleJsonImporter($translator, new QuizCsvImporter($translator), new QuizImportImages($requestStack, $uploads)),
            new ZoneJsonImporter($translator, $uploads),
            new MatchingJsonImporter($translator, new MatchingImageStore($uploads)),
            new NumericJsonImporter($translator),
            new ShortAnswerJsonImporter($translator),
        );

        $this->reader = new QuizImportBatchReader(
            new InteractiveQuizImporterRegistry([$mixed]),
            $mixed,
            new JsonDocumentSplitter(),
            $translator,
        );
    }

    public function testEachDocumentBecomesItsOwnPayload(): void
    {
        $payloads = $this->reader->read([
            ['json' => $this->document('Les réseaux', 'Quelle couche transporte IP ?'), 'fileName' => 'un.json'],
            ['json' => $this->document('Le routage', 'Que fait une table de routage ?'), 'fileName' => 'deux.json'],
        ]);

        self::assertCount(2, $payloads);
        self::assertSame('Les réseaux', $payloads[0]['name']);
        self::assertSame('un.json', $payloads[0]['fileName']);
        self::assertSame('Le routage', $payloads[1]['name']);
        self::assertCount(1, $payloads[1]['questions']);
    }

    public function testAnUnreadableDocumentRefusesTheWholeBatchByItsRank(): void
    {
        try {
            $this->reader->read([
                ['json' => $this->document('Les réseaux', 'Quelle couche transporte IP ?'), 'fileName' => 'un.json'],
                ['json' => '{"format":"nimporte-quoi/1"}', 'fileName' => 'deux.json'],
            ]);
            self::fail('The batch should have been refused.');
        } catch (QuizCsvImportException $exception) {
            self::assertSame('quizBatchDocumentRefusedError', $exception->getMessageKey());
            self::assertSame(2, $exception->getParameters()['%number%']);
            self::assertSame('deux.json', $exception->getParameters()['%file%']);
        }
    }

    public function testABatchWithNoDocumentAtAllIsRefused(): void
    {
        $this->expectException(QuizCsvImportException::class);

        $this->reader->read([]);
    }

    public function testItSplitsAPasteIntoItsDocuments(): void
    {
        $payloads = $this->reader->readPaste(
            $this->document('Les réseaux', 'Quelle couche transporte IP ?')
            ."\n"
            .$this->document('Le routage', 'Que fait une table de routage ?'),
            'collé',
        );

        self::assertCount(2, $payloads);
        self::assertSame('Les réseaux', $payloads[0]['name']);
        self::assertSame('Le routage', $payloads[1]['name']);
    }

    /**
     * A paste holding a single document is not a batch, and saying so here is what lets the paste
     * step keep sending it down the screen it has always used.
     */
    public function testASingleDocumentPasteIsNotABatch(): void
    {
        self::assertCount(1, $this->reader->readPaste($this->document('Les réseaux', 'Quelle couche transporte IP ?'), 'collé'));
    }

    private function document(string $name, string $enonce): string
    {
        return (string) json_encode([
            'format' => MixedJsonImporter::FORMAT,
            'template' => ['name' => $name],
            'questions' => [[
                'type' => 'qcm',
                'label' => $enonce,
                'answers' => ['Une', 'Deux', 'Trois'],
                'correct' => [1],
            ]],
        ], \JSON_UNESCAPED_UNICODE);
    }
}
