<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\BlankMode;
use App\Enum\QuestionType;
use App\Service\QuizCsvImportException;
use App\Service\ShortAnswerExampleCatalog;
use App\Service\ShortAnswerJsonImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-reponse-courte/1" import format, fourth family of the interactive import. Same
 * contract as its siblings; what is specific here is that the document's flat "answers" list has to
 * land as the single blank the type is stored as.
 */
class ShortAnswerJsonImporterTest extends TestCase
{
    private ShortAnswerJsonImporter $importer;

    protected function setUp(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id.(isset($parameters['%number%']) ? ' #'.$parameters['%number%'] : '');
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };

        $this->importer = new ShortAnswerJsonImporter($translator);
    }

    public function testParseAcceptsAWellFormedDocument(): void
    {
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-reponse-courte/1',
            'template' => ['name' => 'SVT', 'subject' => 'SVT'],
            'questions' => [
                [
                    'type' => 'reponse_courte',
                    'label' => 'Quel organite produit l\'ATP ?',
                    'difficulty' => 'moyen',
                    'answers' => ['mitochondrie', 'la mitochondrie'],
                    'tolerateTypo' => true,
                    'explanation' => 'La centrale énergétique de la cellule.',
                ],
            ],
        ]));

        self::assertSame('SVT', $payload['name']);
        self::assertSame([], $payload['errors']);
        self::assertCount(1, $payload['questions']);

        $config = $payload['questions'][0]['blanksConfig'];
        // The flat list becomes the one blank the type is stored as.
        self::assertSame([['answers' => ['mitochondrie', 'la mitochondrie']]], $config['blanks']);
        self::assertSame(BlankMode::Libre->value, $config['mode']);
        self::assertTrue($config['ignoreCase'], 'forgiving case is the default');
        self::assertTrue($config['tolerateTypo']);
    }

    public function testAQuestionWithoutAnyAcceptedAnswerIsSkipped(): void
    {
        // It would be marked wrong for everyone - the one failure worth being fatal here.
        foreach ([[], null, ['  ', '']] as $answers) {
            $payload = $this->importer->parse($this->document(['answers' => $answers]));
            self::assertSame(['shortAnswerImportQuestionNoAnswerError #1'], $payload['errors']);
        }
    }

    public function testAQuestionWithoutALabelIsSkipped(): void
    {
        $payload = $this->importer->parse($this->document(['label' => '']));

        self::assertSame(['shortAnswerImportQuestionNoLabelError #1'], $payload['errors']);
    }

    public function testAQuestionNamingAnotherTypeIsReported(): void
    {
        $payload = $this->importer->parse($this->document(['type' => 'numerique']));

        self::assertSame(['shortAnswerImportQuestionBadTypeError #1'], $payload['errors']);
    }

    public function testOneUnusableQuestionDoesNotCostTheOthers(): void
    {
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-reponse-courte/1',
            'questions' => [
                ['label' => 'Sans réponse acceptée.', 'answers' => []],
                ['label' => 'Capitale de la France ?', 'answers' => ['Paris']],
            ],
        ]));

        self::assertCount(1, $payload['questions']);
        self::assertSame(['shortAnswerImportQuestionNoAnswerError #1'], $payload['errors']);
    }

    public function testVariantsAreTrimmedAndDeduplicatedCaseBlind(): void
    {
        // "Photosynthèse" next to "photosynthèse" is not a variant - the matching already forgives
        // case, so keeping both would only clutter the correction listing.
        $payload = $this->importer->parse($this->document([
            'answers' => ['  photosynthèse ', 'Photosynthèse', 'la photosynthèse'],
        ]));

        $config = $payload['questions'][0]['blanksConfig'];
        self::assertSame([['answers' => ['photosynthèse', 'la photosynthèse']]], $config['blanks']);
    }

    public function testUnreadableDocumentsAreFatal(): void
    {
        $this->expectException(QuizCsvImportException::class);
        $this->importer->parse('{not json');
    }

    public function testAWrongFormatTagIsFatal(): void
    {
        $this->expectException(QuizCsvImportException::class);
        $this->importer->parse((string) json_encode(['format' => 'moncampus-numerique/1', 'questions' => [[]]]));
    }

    public function testAnEmptyQuestionListIsFatal(): void
    {
        $this->expectException(QuizCsvImportException::class);
        $this->importer->parse((string) json_encode(['format' => 'moncampus-reponse-courte/1', 'questions' => []]));
    }

    public function testAppendQuestionsBuildsGradableEntities(): void
    {
        $payload = $this->importer->parse($this->document(['points' => 2]));

        $template = new QuizTemplate(new User('teacher'));
        $this->importer->appendQuestions($template, $payload['questions']);

        self::assertCount(1, $template->getQuestions());
        $question = $template->getQuestions()->first();
        self::assertSame(QuestionType::ReponseCourte, $question->getType());
        self::assertSame(2.0, $question->getPoints());
        self::assertSame(1, $question->getBlankCount());
        self::assertSame([['photosynthèse', 'la photosynthèse']], $question->getBlankAnswers());
        self::assertSame(1, $question->getOrderIndex());
    }

    public function testExportRoundTripsThroughParse(): void
    {
        $payload = $this->importer->parse($this->document(['tolerateTypo' => true, 'explanation' => 'Dans les chloroplastes.']));

        $template = new QuizTemplate(new User('teacher'));
        $template->setName('SVT');
        $this->importer->appendQuestions($template, $payload['questions']);

        $document = $this->importer->export($template);
        self::assertSame(ShortAnswerJsonImporter::FORMAT, $document['format']);

        $reparsed = $this->importer->parse((string) json_encode($document));
        self::assertSame([], $reparsed['errors']);
        self::assertSame(
            $payload['questions'][0]['blanksConfig'],
            $reparsed['questions'][0]['blanksConfig'],
        );
        self::assertSame('Dans les chloroplastes.', $reparsed['questions'][0]['explanation']);
    }

    public function testExportSkipsATemplateWithoutShortAnswers(): void
    {
        self::assertSame([], $this->importer->export(new QuizTemplate(new User('teacher')))['questions']);
    }

    /**
     * Every catalogue entry has to be readable by the importer it illustrates - a broken example is
     * worse than none, since it is the first thing a teacher clicks.
     */
    public function testEveryCatalogueExampleParsesCleanly(): void
    {
        foreach (array_keys(ShortAnswerExampleCatalog::labels()) as $key) {
            $json = ShortAnswerExampleCatalog::json($key);
            self::assertIsString($json, sprintf('example "%s" has no document', $key));

            $payload = $this->importer->parse($json);
            self::assertSame([], $payload['errors'], sprintf('example "%s" reported errors', $key));
            self::assertNotSame([], $payload['questions'], sprintf('example "%s" produced no question', $key));

            // Every example must show what the type is for: more than one accepted wording.
            foreach ($payload['questions'] as $question) {
                $blanks = $question['blanksConfig']['blanks'];
                self::assertIsArray($blanks);
                self::assertIsArray($blanks[0]);
                self::assertIsArray($blanks[0]['answers']);
                self::assertGreaterThan(
                    1,
                    \count($blanks[0]['answers']),
                    sprintf('example "%s" has a question with a single accepted wording', $key),
                );
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function document(array $overrides): string
    {
        return (string) json_encode([
            'format' => 'moncampus-reponse-courte/1',
            'questions' => [array_merge([
                'type' => 'reponse_courte',
                'label' => 'Comment appelle-t-on ce processus ?',
                'answers' => ['photosynthèse', 'la photosynthèse'],
            ], $overrides)],
        ]);
    }
}
