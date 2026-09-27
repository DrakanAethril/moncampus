<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\EvaluationRubricSection;
use App\Entity\Topic;
use App\Enum\RubricSectionKind;
use App\Service\EvaluationRubricImportException;
use App\Service\EvaluationRubricJsonImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « moncampus-bareme/1 » - a barème as a document, read **strictly**.
 *
 * The rubric editor skips a blank row silently, because a teacher typing in it would lose their work
 * over one empty line. A document has nobody typing: a row it cannot carry is an error that names
 * where it is, so whoever wrote the document - Claude, most of the time - can correct it.
 */
class EvaluationRubricJsonImporterTest extends TestCase
{
    private EvaluationRubricJsonImporter $importer;

    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key, array $parameters = []): string => $key.' '.json_encode($parameters, \JSON_UNESCAPED_UNICODE));

        $this->importer = new EvaluationRubricJsonImporter($translator);
    }

    public function testReadsPartsBonusAndMalusInOrder(): void
    {
        $rubric = $this->importer->parse($this->document());

        self::assertSame([], $rubric['errors']);
        self::assertSame(['Partie 1 - Adressage', 'Partie 2 - VLAN'], array_column($rubric['sections'], 'name'));
        self::assertSame([['label' => '1a', 'maxPoints' => 2.0], ['label' => '1b', 'maxPoints' => 1.5]], $rubric['sections'][0]['questions']);
        self::assertSame([['label' => 'B', 'maxPoints' => 1.0]], $rubric['bonus']);
        self::assertSame([['label' => 'Orthographe', 'maxPoints' => 2.0]], $rubric['malus']);
        // Bonus and malus never count towards what the evaluation is marked out of.
        self::assertSame(20.0, $rubric['standardTotal']);
    }

    public function testNamesEveryRowItCannotCarry(): void
    {
        $rubric = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-bareme/1',
            'sections' => [
                ['name' => 'Partie 1', 'questions' => [
                    ['label' => 'Question beaucoup trop longue', 'maxPoints' => 2],
                    ['label' => '1b', 'maxPoints' => 0],
                    ['label' => '', 'maxPoints' => 1],
                ]],
                ['name' => '', 'questions' => [['label' => '2a', 'maxPoints' => 1]]],
            ],
        ]));

        self::assertCount(4, $rubric['errors']);
        self::assertStringContainsString('"%section%":1,"%question%":1', $rubric['errors'][0]);
        self::assertStringStartsWith('rubricImportLabelTooLongError', $rubric['errors'][0]);
        self::assertStringStartsWith('rubricImportPointsError', $rubric['errors'][1]);
        self::assertStringStartsWith('rubricImportLabelMissingError', $rubric['errors'][2]);
        self::assertStringStartsWith('rubricImportSectionNameMissingError', $rubric['errors'][3]);
    }

    public function testRefusesADocumentOfAnotherFormat(): void
    {
        $this->expectException(EvaluationRubricImportException::class);

        $this->importer->parse('{"format":"moncampus-quiz/1","sections":[]}');
    }

    public function testRefusesABaremeWithoutAnyPart(): void
    {
        $this->expectException(EvaluationRubricImportException::class);

        $this->importer->parse('{"format":"moncampus-bareme/1","sections":[],"bonus":[{"label":"B","maxPoints":1}]}');
    }

    public function testExportsWhatItReads(): void
    {
        $evaluation = new Evaluation($this->createStub(Topic::class), 'DS 1', new \DateTimeImmutable('2026-10-01'));
        $part = new EvaluationRubricSection('Partie 1', 0, RubricSectionKind::Standard);
        $part->addQuestion(new EvaluationRubricQuestion('1a', 3.0, 0));
        $evaluation->addRubricSection($part);
        $malus = new EvaluationRubricSection('', 1, RubricSectionKind::Malus);
        $malus->addQuestion(new EvaluationRubricQuestion('Retard', 1.0, 0));
        $evaluation->addRubricSection($malus);

        $document = $this->importer->export($evaluation);

        self::assertSame('moncampus-bareme/1', $document['format']);
        $rubric = $this->importer->parse((string) json_encode($document));
        self::assertSame([['name' => 'Partie 1', 'questions' => [['label' => '1a', 'maxPoints' => 3.0]]]], $rubric['sections']);
        self::assertSame([], $rubric['bonus']);
        self::assertSame([['label' => 'Retard', 'maxPoints' => 1.0]], $rubric['malus']);
    }

    private function document(): string
    {
        return (string) json_encode([
            'format' => 'moncampus-bareme/1',
            'sections' => [
                ['name' => 'Partie 1 - Adressage', 'questions' => [['label' => '1a', 'maxPoints' => 2], ['label' => '1b', 'maxPoints' => '1.5']]],
                ['name' => 'Partie 2 - VLAN', 'questions' => [['label' => '2a', 'maxPoints' => 10], ['label' => '2b', 'maxPoints' => 6.5]]],
            ],
            'bonus' => [['label' => 'B', 'maxPoints' => 1]],
            'malus' => [['label' => 'Orthographe', 'maxPoints' => 2]],
        ]);
    }
}
