<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\EvaluationRubricSection;
use App\Entity\Grade;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\GradeStatus;
use App\Enum\RubricSectionKind;
use App\Repository\GradeRepository;
use App\Service\EvaluationAverageCalculator;
use App\Service\GradeEntryParser;
use App\Service\GradeEntryWriter;
use App\Service\InvalidRubricPoints;
use App\Service\RubricPointsParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * What typing in the carnet de notes writes - the rule the entry screen and the Claude connector
 * share: a cell holds a status and a value, a question's box holds points and the row's total is
 * recomputed from them, an emptied cell removes the grade rather than storing a zero.
 */
final class GradeEntryWriterTest extends TestCase
{
    private User $teacher;
    private User $student;
    /** @var list<object> */
    private array $persisted = [];
    /** @var list<object> */
    private array $removed = [];

    protected function setUp(): void
    {
        $this->teacher = new User('prof');
        $this->student = new User('eleve');
    }

    public function testACellCreatesTheGradeAndStampsWhoEnteredIt(): void
    {
        $evaluation = $this->evaluation();

        $grade = $this->writer()->writeCell($evaluation, $this->student, '14,5', $this->teacher);

        self::assertInstanceOf(Grade::class, $grade);
        self::assertSame(GradeStatus::Normal, $grade->getStatus());
        self::assertSame(14.5, $grade->getValue());
        self::assertSame($this->teacher, $grade->getGradedBy());
        self::assertSame('2026-10-09 10:00', $grade->getGradedAt()?->format('Y-m-d H:i'));
        self::assertSame([$grade], $this->persisted);
    }

    public function testACellRewritesTheGradeThereIs(): void
    {
        $evaluation = $this->evaluation();
        $existing = (new Grade($evaluation, $this->student))->setValue(8.0);

        $grade = $this->writer($existing)->writeCell($evaluation, $this->student, 'abs', $this->teacher);

        self::assertSame($existing, $grade);
        self::assertSame(GradeStatus::Absent, $existing->getStatus());
        self::assertNull($existing->getValue());
        self::assertSame([], $this->persisted);
    }

    /** A blank and a nought are not the same statement about a student. */
    public function testAnEmptiedCellRemovesTheGrade(): void
    {
        $evaluation = $this->evaluation();
        $existing = (new Grade($evaluation, $this->student))->setValue(8.0);

        self::assertNull($this->writer($existing)->writeCell($evaluation, $this->student, '', $this->teacher));
        self::assertSame([$existing], $this->removed);
    }

    public function testAnEmptyCellOnAStudentWithoutGradeWritesNothing(): void
    {
        self::assertNull($this->writer()->writeCell($this->evaluation(), $this->student, '  ', $this->teacher));
        self::assertSame([], $this->persisted);
        self::assertSame([], $this->removed);
    }

    /** A caller writing a whole class holds each row already: it is not looked up again. */
    public function testAGradeHandedOverIsTheOneWritten(): void
    {
        $evaluation = $this->evaluation();
        $held = new Grade($evaluation, $this->student);

        $repository = $this->createMock(GradeRepository::class);
        $repository->expects(self::never())->method('findOneForEvaluationAndStudent');

        $grade = $this->writer(repository: $repository)->writeCell($evaluation, $this->student, '12', $this->teacher, $held);

        self::assertSame($held, $grade);
        self::assertSame(12.0, $held->getValue());
    }

    public function testAQuestionBoxWritesItsPointsAndTheTotalFollows(): void
    {
        $evaluation = $this->evaluation();
        [$first, $second] = $this->questions($evaluation, RubricSectionKind::Standard, [4.0, 6.0]);
        [$malus] = $this->questions($evaluation, RubricSectionKind::Malus, [1.0]);
        $writer = $this->writer();

        $grade = $writer->writeAnswer($evaluation, $this->student, $first, '3', $this->teacher);
        $writer->writeAnswer($evaluation, $this->student, $second, '5,5', $this->teacher, $grade);
        $writer->writeAnswer($evaluation, $this->student, $malus, '1', $this->teacher, $grade);

        self::assertSame(GradeStatus::Normal, $grade->getStatus());
        self::assertSame(7.5, $grade->getValue());
        self::assertCount(3, $grade->getRubricAnswers());
        self::assertSame($this->teacher, $grade->getGradedBy());
    }

    public function testABoxAlreadyFilledIsRewrittenNotDoubled(): void
    {
        $evaluation = $this->evaluation();
        [$question] = $this->questions($evaluation, RubricSectionKind::Standard, [4.0]);
        $writer = $this->writer();

        $grade = $writer->writeAnswer($evaluation, $this->student, $question, '3', $this->teacher);
        $writer->writeAnswer($evaluation, $this->student, $question, 'nt', $this->teacher, $grade);

        self::assertCount(1, $grade->getRubricAnswers());
        $answer = $grade->getRubricAnswers()->first();
        self::assertNotFalse($answer);
        self::assertTrue($answer->isNotTested());
        self::assertNull($answer->getPointsAwarded());
        // « Non traité » counts as answered: the row totals 0, it is not empty.
        self::assertSame(0.0, $grade->getValue());
    }

    public function testAnEmptiedBoxLeavesTheRowWithoutTotal(): void
    {
        $evaluation = $this->evaluation();
        [$question] = $this->questions($evaluation, RubricSectionKind::Standard, [4.0]);
        $writer = $this->writer();

        $grade = $writer->writeAnswer($evaluation, $this->student, $question, '3', $this->teacher);
        $writer->writeAnswer($evaluation, $this->student, $question, '', $this->teacher, $grade);

        self::assertNull($grade->getValue());
    }

    /** Refused before anything is created: a wrong box must not leave an empty grade behind. */
    public function testARefusedBoxWritesNothing(): void
    {
        $evaluation = $this->evaluation();
        [$question] = $this->questions($evaluation, RubricSectionKind::Standard, [4.0]);

        try {
            $this->writer()->writeAnswer($evaluation, $this->student, $question, '5', $this->teacher);
            self::fail('Expected the box to be refused.');
        } catch (InvalidRubricPoints $exception) {
            self::assertSame(InvalidRubricPoints::EXCEEDS_MAX_POINTS, $exception->reason);
        }

        self::assertSame([], $this->persisted);
    }

    private function writer(?Grade $stored = null, ?GradeRepository $repository = null): GradeEntryWriter
    {
        if (null === $repository) {
            $repository = $this->createStub(GradeRepository::class);
            $repository->method('findOneForEvaluationAndStudent')->willReturn($stored);
        }

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });

        return new GradeEntryWriter(
            $repository,
            $entityManager,
            new GradeEntryParser(),
            new RubricPointsParser(),
            new EvaluationAverageCalculator(),
            new MockClock('2026-10-09 10:00:00'),
        );
    }

    private function evaluation(): Evaluation
    {
        return new Evaluation($this->createStub(Topic::class), 'DS', new \DateTimeImmutable('2026-10-09'));
    }

    /**
     * @param list<float> $maxPoints
     *
     * @return list<EvaluationRubricQuestion>
     */
    private function questions(Evaluation $evaluation, RubricSectionKind $kind, array $maxPoints): array
    {
        $section = new EvaluationRubricSection($kind->isStandard() ? 'Partie' : '', $evaluation->getRubricSections()->count(), $kind);
        $questions = [];
        foreach ($maxPoints as $index => $max) {
            $questions[] = $question = new EvaluationRubricQuestion((string) ($index + 1), $max, $index);
            $section->addQuestion($question);
        }
        $evaluation->addRubricSection($section);

        return $questions;
    }
}
