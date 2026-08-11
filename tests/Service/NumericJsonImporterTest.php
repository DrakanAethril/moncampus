<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Service\NumericExampleCatalog;
use App\Service\NumericJsonImporter;
use App\Service\QuizCsvImportException;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-numerique/1" import format. Same contract as its two siblings - parse() never
 * touches Doctrine, a question the reader cannot use is skipped and reported rather than fatal, and
 * only appendQuestions() builds entities - but with a longer hard-error tier, because a calculée
 * has several ways of being silently unanswerable that only a check here can catch.
 */
class NumericJsonImporterTest extends TestCase
{
    private NumericJsonImporter $importer;

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

        $this->importer = new NumericJsonImporter($translator);
    }

    public function testParseAcceptsBothTypesInOneDocument(): void
    {
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-numerique/1',
            'template' => ['name' => 'Physique', 'subject' => 'Physique-chimie'],
            'questions' => [
                [
                    'type' => 'numerique',
                    'label' => 'Vitesse de la lumière en km/s ?',
                    'answer' => 299792,
                    'tolerance' => 1,
                    'unit' => 'km/s',
                    'decimals' => 0,
                ],
                [
                    'type' => 'calculee',
                    'label' => 'Un train roule à {v} km/h pendant {t} h.',
                    'difficulty' => 'moyen',
                    'formula' => 'v * t',
                    'variables' => [
                        ['name' => 'v', 'min' => 80, 'max' => 140, 'step' => 10],
                        ['name' => 't', 'min' => 1, 'max' => 4, 'step' => 0.5, 'decimals' => 1],
                    ],
                    'unit' => 'km',
                ],
            ],
        ]));

        self::assertSame('Physique', $payload['name']);
        self::assertSame([], $payload['errors']);
        self::assertCount(2, $payload['questions']);

        self::assertSame(299792.0, $payload['questions'][0]['numericConfig']['answer']);
        self::assertSame('km/s', $payload['questions'][0]['numericConfig']['unit']);
        self::assertSame('v * t', $payload['questions'][1]['numericConfig']['formula']);
        self::assertSame(['v', 't'], array_column($payload['questions'][1]['numericConfig']['variables'], 'name'));
        // Defaults fill in for anything the document left out.
        self::assertSame(2.0, $payload['questions'][1]['numericConfig']['tolerance']);
        self::assertSame('percent', $payload['questions'][1]['numericConfig']['toleranceMode']);
    }

    // --- the hard tier: everything that makes a question unanswerable ---

    public function testANumeriqueWithoutANumericAnswerIsSkipped(): void
    {
        foreach ([null, 'beaucoup', ''] as $answer) {
            $payload = $this->importer->parse($this->document(['answer' => $answer], type: 'numerique'));
            self::assertSame(['numericImportQuestionNoAnswerError #1'], $payload['errors']);
        }
    }

    public function testAFormulaThatDoesNotParseIsSkipped(): void
    {
        $payload = $this->importer->parse($this->document(['formula' => 'v *']));

        self::assertSame(['numericImportQuestionBadFormulaError #1'], $payload['errors']);
    }

    public function testAFormulaReadingAnUndeclaredNameIsSkipped(): void
    {
        // It would evaluate to nothing, for every student - the single worst way for a calculée to
        // be wrong, because the editor shows nothing amiss.
        $payload = $this->importer->parse($this->document([
            'label' => 'Chute de {h} m avec g.',
            'formula' => 'sqrt(2 * g * h)',
            'variables' => [['name' => 'h', 'min' => 5, 'max' => 45]],
        ]));

        self::assertSame(['numericImportQuestionFormulaUnknownNameError #1'], $payload['errors']);
    }

    public function testAVariableMissingFromTheStatementIsSkipped(): void
    {
        // The student would be asked to compute with a number nobody ever showed them.
        $payload = $this->importer->parse($this->document([
            'label' => 'Un train roule à {v} km/h. Quelle distance ?',
            'formula' => 'v * t',
            'variables' => [
                ['name' => 'v', 'min' => 80, 'max' => 140],
                ['name' => 't', 'min' => 1, 'max' => 4],
            ],
        ]));

        self::assertSame(['numericImportQuestionVariableNotInStatementError #1'], $payload['errors']);
    }

    public function testAFormulaThatCannotComputeOverItsRangesIsSkipped(): void
    {
        // A range that spans zero under a division: fine for most draws, fatal for one of them.
        $payload = $this->importer->parse($this->document([
            'label' => 'Rapport de {a} sur {b}.',
            'formula' => 'a / b',
            'variables' => [
                ['name' => 'a', 'min' => 1, 'max' => 10],
                ['name' => 'b', 'min' => -5, 'max' => 5],
            ],
        ]));

        self::assertSame(['numericImportQuestionFormulaDoesNotComputeError #1'], $payload['errors']);
    }

    public function testACalculeeWithoutVariablesIsSkipped(): void
    {
        $payload = $this->importer->parse($this->document(['formula' => '2 * 3', 'variables' => []]));

        self::assertSame(['numericImportQuestionNoVariableError #1'], $payload['errors']);
    }

    public function testAQuestionOfAnotherFamilyIsReported(): void
    {
        $payload = $this->importer->parse($this->document(['type' => 'apparier']));

        self::assertSame(['numericImportQuestionBadTypeError #1'], $payload['errors']);
    }

    public function testOneUnusableQuestionDoesNotCostTheOthers(): void
    {
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-numerique/1',
            'questions' => [
                ['type' => 'numerique', 'label' => 'Sans réponse.'],
                ['type' => 'numerique', 'label' => 'Combien ?', 'answer' => 12],
            ],
        ]));

        self::assertCount(1, $payload['questions']);
        self::assertSame(['numericImportQuestionNoAnswerError #1'], $payload['errors']);
    }

    // --- the soft tier ---

    public function testAnUnusableVariableRowIsDroppedRatherThanFatal(): void
    {
        // The remaining ones still make a question; only a formula naming the dropped one would.
        $payload = $this->importer->parse($this->document([
            'label' => 'Un train roule à {v} km/h pendant {t} h.',
            'formula' => 'v * t',
            'variables' => [
                ['name' => 'v', 'min' => 80, 'max' => 140],
                ['name' => 't', 'min' => 1, 'max' => 4],
                ['name' => 'ignored', 'min' => 1],
                'not an array',
            ],
        ]));

        self::assertSame([], $payload['errors']);
        self::assertSame(['v', 't'], array_column($payload['questions'][0]['numericConfig']['variables'], 'name'));
    }

    public function testARangeWrittenBackwardsIsReadTheWayItWasMeant(): void
    {
        $payload = $this->importer->parse($this->document([
            'label' => 'Valeur de {v}.',
            'formula' => 'v',
            'variables' => [['name' => 'v', 'min' => 140, 'max' => 80]],
        ]));

        $variables = $payload['questions'][0]['numericConfig']['variables'];
        self::assertIsArray($variables);
        self::assertSame([['name' => 'v', 'min' => 80.0, 'max' => 140.0, 'step' => 1.0, 'decimals' => 0]], $variables);
    }

    // --- document-level failures ---

    public function testUnreadableDocumentsAreFatal(): void
    {
        $this->expectException(QuizCsvImportException::class);
        $this->importer->parse('{not json');
    }

    public function testAWrongFormatTagIsFatal(): void
    {
        $this->expectException(QuizCsvImportException::class);
        $this->importer->parse((string) json_encode(['format' => 'moncampus-zones/1', 'questions' => [[]]]));
    }

    public function testAnEmptyQuestionListIsFatal(): void
    {
        $this->expectException(QuizCsvImportException::class);
        $this->importer->parse((string) json_encode(['format' => 'moncampus-numerique/1', 'questions' => []]));
    }

    // --- entities and round trip ---

    public function testAppendQuestionsBuildsEntitiesAtTheEndOfTheBank(): void
    {
        $payload = $this->importer->parse($this->document(['points' => 3]));

        $template = new QuizTemplate(new User('teacher'));
        $this->importer->appendQuestions($template, $payload['questions']);

        self::assertCount(1, $template->getQuestions());
        $question = $template->getQuestions()->first();
        self::assertSame(QuestionType::Calculee, $question->getType());
        self::assertSame(3.0, $question->getPoints());
        self::assertSame('v * t', $question->getNumericFormula());
        self::assertSame(['v', 't'], array_column($question->getNumericVariables(), 'name'));
        self::assertSame(1, $question->getOrderIndex());
    }

    public function testExportRoundTripsThroughParse(): void
    {
        $payload = $this->importer->parse($this->document(['unit' => 'km', 'unitRequired' => true, 'explanation' => 'd = v × t']));

        $template = new QuizTemplate(new User('teacher'));
        $template->setName('Physique');
        $this->importer->appendQuestions($template, $payload['questions']);

        $document = $this->importer->export($template);
        self::assertSame(NumericJsonImporter::FORMAT, $document['format']);

        $reparsed = $this->importer->parse((string) json_encode($document));
        self::assertSame([], $reparsed['errors']);
        self::assertSame(
            $payload['questions'][0]['numericConfig'],
            $reparsed['questions'][0]['numericConfig'],
        );
        self::assertSame('d = v × t', $reparsed['questions'][0]['explanation']);
    }

    public function testExportSkipsATemplateWithoutNumericQuestions(): void
    {
        self::assertSame([], $this->importer->export(new QuizTemplate(new User('teacher')))['questions']);
    }

    /**
     * Every catalogue entry has to be readable by the importer it illustrates - a broken example is
     * worse than none, since it is the first thing a teacher clicks. This also re-checks every
     * formula, every range and every statement of the catalogue against the real validation.
     */
    public function testEveryCatalogueExampleParsesCleanly(): void
    {
        foreach (array_keys(NumericExampleCatalog::labels()) as $key) {
            $json = NumericExampleCatalog::json($key);
            self::assertIsString($json, sprintf('example "%s" has no document', $key));

            $payload = $this->importer->parse($json);
            self::assertSame([], $payload['errors'], sprintf('example "%s" reported errors', $key));
            self::assertNotSame([], $payload['questions'], sprintf('example "%s" produced no question', $key));
        }
    }

    /** @param array<string, mixed> $overrides */
    private function document(array $overrides, string $type = 'calculee'): string
    {
        $base = 'calculee' === $type
            ? [
                'type' => 'calculee',
                'label' => 'Un train roule à {v} km/h pendant {t} h.',
                'formula' => 'v * t',
                'variables' => [
                    ['name' => 'v', 'min' => 80, 'max' => 140, 'step' => 10],
                    ['name' => 't', 'min' => 1, 'max' => 4, 'step' => 0.5, 'decimals' => 1],
                ],
            ]
            : ['type' => 'numerique', 'label' => 'Combien ?', 'answer' => 42];

        return (string) json_encode([
            'format' => 'moncampus-numerique/1',
            'questions' => [array_merge($base, $overrides)],
        ]);
    }
}
