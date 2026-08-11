<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Service\MatchingExampleCatalog;
use App\Service\MatchingJsonImporter;
use App\Service\QuizCsvImportException;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-apparier/1" import format - the paste-a-JSON way into apparier questions, built to
 * be produced by a language model from the copyable prompt on the import screen. Same contract as
 * QuizCsvImporter and ZoneJsonImporter: parse() never touches Doctrine, a question the reader
 * cannot use is skipped and reported rather than fatal, and only appendQuestions() builds entities
 * after the teacher confirmed the preview.
 */
class MatchingJsonImporterTest extends TestCase
{
    private MatchingJsonImporter $importer;

    protected function setUp(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                // The key itself, plus the question number when given - enough to assert on.
                return $id.(isset($parameters['%number%']) ? ' #'.$parameters['%number%'] : '');
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };

        $this->importer = new MatchingJsonImporter($translator);
    }

    public function testParseAcceptsAWellFormedDocument(): void
    {
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-apparier/1',
            'template' => ['name' => 'Géographie', 'subject' => 'Histoire-géographie'],
            'questions' => [
                [
                    'type' => 'apparier',
                    'label' => 'Reliez chaque pays à sa capitale.',
                    'difficulty' => 'facile',
                    'columns' => ['left' => 'Pays', 'right' => 'Capitale'],
                    'pairs' => [
                        ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
                        ['id' => 'p2', 'left' => 'Italie', 'right' => 'Rome'],
                    ],
                    'distractors' => ['Bruxelles'],
                    'feedback' => ['p1' => 'Paris depuis 987.', '*' => 'Relisez la carte.'],
                    'explanation' => 'Quatre capitales fondatrices.',
                ],
            ],
        ]));

        self::assertSame('Géographie', $payload['name']);
        self::assertSame('Histoire-géographie', $payload['subject']);
        self::assertSame([], $payload['errors']);
        self::assertCount(1, $payload['questions']);

        $question = $payload['questions'][0];
        self::assertSame('apparier', $question['type']);
        self::assertSame('facile', $question['difficulty']);
        self::assertSame('Pays', $question['matchingConfig']['leftHeader']);
        self::assertSame(['Bruxelles'], $question['matchingConfig']['distractors']);
        self::assertSame(['p1' => 'Paris depuis 987.', '*' => 'Relisez la carte.'], $question['matchingConfig']['feedback']);
        self::assertCount(2, $question['matchingConfig']['pairs']);
    }

    public function testAMissingPairIdIsFilledInByPosition(): void
    {
        $payload = $this->importer->parse($this->document([
            'pairs' => [
                ['left' => 'France', 'right' => 'Paris'],
                ['left' => 'Italie', 'right' => 'Rome'],
            ],
        ]));

        self::assertSame(['p1', 'p2'], array_column($payload['questions'][0]['matchingConfig']['pairs'], 'id'));
    }

    public function testAQuestionWithFewerThanTwoUsablePairsIsSkippedAndReported(): void
    {
        $payload = $this->importer->parse($this->document([
            'pairs' => [
                ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
                ['id' => 'p2', 'left' => 'Italie'],
            ],
        ]));

        self::assertSame([], $payload['questions']);
        self::assertSame(['matchingImportQuestionNotEnoughPairsError #1'], $payload['errors']);
    }

    public function testOneUnusableQuestionDoesNotCostTheOthers(): void
    {
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-apparier/1',
            'questions' => [
                ['type' => 'apparier', 'label' => '', 'pairs' => []],
                [
                    'type' => 'apparier',
                    'label' => 'Reliez.',
                    'pairs' => [
                        ['id' => 'p1', 'left' => 'a', 'right' => 'A'],
                        ['id' => 'p2', 'left' => 'b', 'right' => 'B'],
                    ],
                ],
            ],
        ]));

        self::assertCount(1, $payload['questions']);
        self::assertSame(['matchingImportQuestionNoLabelError #1'], $payload['errors']);
    }

    public function testADistractorRepeatingARealAnswerIsDropped(): void
    {
        // It would grade as correct anyway (the checker compares texts), so as a decoy it only
        // takes up room - dropping it silently is the decoration tier of the validation.
        $payload = $this->importer->parse($this->document([
            'pairs' => [
                ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
                ['id' => 'p2', 'left' => 'Italie', 'right' => 'Rome'],
            ],
            'distractors' => ['Paris', 'Bruxelles'],
        ]));

        self::assertSame(['Bruxelles'], $payload['questions'][0]['matchingConfig']['distractors']);
    }

    public function testAFeedbackKeyedOnNothingIsDroppedRatherThanFatal(): void
    {
        $payload = $this->importer->parse($this->document([
            'pairs' => [
                ['id' => 'p1', 'left' => 'a', 'right' => 'A'],
                ['id' => 'p2', 'left' => 'b', 'right' => 'B'],
            ],
            'feedback' => ['p1' => 'Bien.', 'p9' => 'Hallucination.', '*' => 'Défaut.'],
        ]));

        self::assertSame([], $payload['errors']);
        self::assertSame(['p1' => 'Bien.', '*' => 'Défaut.'], $payload['questions'][0]['matchingConfig']['feedback']);
    }

    public function testAQuestionNamingAnotherTypeIsReported(): void
    {
        $payload = $this->importer->parse($this->document(['type' => 'legende']));

        self::assertSame(['matchingImportQuestionBadTypeError #1'], $payload['errors']);
    }

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
        $this->importer->parse((string) json_encode(['format' => 'moncampus-apparier/1', 'questions' => []]));
    }

    public function testAppendQuestionsBuildsEntitiesAtTheEndOfTheBank(): void
    {
        $payload = $this->importer->parse($this->document([
            'pairs' => [
                ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
                ['id' => 'p2', 'left' => 'Italie', 'right' => 'Rome'],
            ],
            'points' => 2,
        ]));

        $template = new QuizTemplate(new User('teacher'));
        $this->importer->appendQuestions($template, $payload['questions']);

        self::assertCount(1, $template->getQuestions());
        $question = $template->getQuestions()->first();
        self::assertSame(QuestionType::Apparier, $question->getType());
        self::assertSame(2.0, $question->getPoints());
        self::assertSame(['p1', 'p2'], $question->getMatchingPairIds());
        self::assertSame(1, $question->getOrderIndex());
    }

    public function testExportRoundTripsThroughParse(): void
    {
        $payload = $this->importer->parse($this->document([
            'pairs' => [
                ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
                ['id' => 'p2', 'left' => 'Italie', 'right' => 'Rome'],
            ],
            'distractors' => ['Bruxelles'],
            'feedback' => ['p1' => 'Bien.'],
        ]));

        $template = new QuizTemplate(new User('teacher'));
        $template->setName('Géographie');
        $this->importer->appendQuestions($template, $payload['questions']);

        $document = $this->importer->export($template);
        self::assertSame(MatchingJsonImporter::FORMAT, $document['format']);

        $reparsed = $this->importer->parse((string) json_encode($document));
        self::assertSame([], $reparsed['errors']);
        self::assertSame(
            $payload['questions'][0]['matchingConfig']['pairs'],
            $reparsed['questions'][0]['matchingConfig']['pairs'],
        );
        self::assertSame(['Bruxelles'], $reparsed['questions'][0]['matchingConfig']['distractors']);
        self::assertSame(['p1' => 'Bien.'], $reparsed['questions'][0]['matchingConfig']['feedback']);
    }

    public function testExportSkipsATemplateWithoutMatchingQuestions(): void
    {
        $document = $this->importer->export(new QuizTemplate(new User('teacher')));

        self::assertSame([], $document['questions']);
    }

    /**
     * Every catalogue entry has to be readable by the importer it illustrates - a broken example is
     * worse than none, since it is the first thing a teacher clicks.
     */
    public function testEveryCatalogueExampleParsesCleanly(): void
    {
        foreach (array_keys(MatchingExampleCatalog::labels()) as $key) {
            $json = MatchingExampleCatalog::json($key);
            self::assertIsString($json, sprintf('example "%s" has no document', $key));

            $payload = $this->importer->parse($json);
            self::assertSame([], $payload['errors'], sprintf('example "%s" reported errors', $key));
            self::assertNotSame([], $payload['questions'], sprintf('example "%s" produced no question', $key));
        }
    }

    /** @param array<string, mixed> $overrides */
    private function document(array $overrides): string
    {
        return (string) json_encode([
            'format' => 'moncampus-apparier/1',
            'questions' => [array_merge([
                'type' => 'apparier',
                'label' => 'Reliez chaque élément.',
                'pairs' => [
                    ['id' => 'p1', 'left' => 'a', 'right' => 'A'],
                    ['id' => 'p2', 'left' => 'b', 'right' => 'B'],
                ],
            ], $overrides)],
        ]);
    }
}
