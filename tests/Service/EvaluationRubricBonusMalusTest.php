<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\EvaluationRubricSection;
use App\Entity\Grade;
use App\Entity\GradeRubricAnswer;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\RubricSectionKind;
use App\Service\EvaluationAverageCalculator;
use App\Service\EvaluationRubricBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The one rule bonus and malus exist for: they move the grade without moving what it is marked out
 * of. Nothing on screen states it - the teacher sees « Total /20 » above a column that can answer
 * 22, and only the arithmetic here says that is intended rather than a rounding accident.
 */
class EvaluationRubricBonusMalusTest extends TestCase
{
    private EvaluationAverageCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new EvaluationAverageCalculator();
    }

    public function testBonusPointsPushTheTotalAboveTheRubricsOwnScale(): void
    {
        $evaluation = $this->evaluation();
        $standard = $this->section($evaluation, RubricSectionKind::Standard, [20.0]);
        $bonus = $this->section($evaluation, RubricSectionKind::Bonus, [2.0]);

        self::assertSame(20.0, $evaluation->getRubricReferencePoints());

        $grade = $this->grade($evaluation, [
            [$standard->getQuestions()->first(), 20.0],
            [$bonus->getQuestions()->first(), 2.0],
        ]);

        self::assertSame(22.0, $this->calculator->computeRubricTotal($grade));
    }

    public function testMalusPointsAreSubtracted(): void
    {
        $evaluation = $this->evaluation();
        $standard = $this->section($evaluation, RubricSectionKind::Standard, [20.0]);
        $malus = $this->section($evaluation, RubricSectionKind::Malus, [4.0]);

        $grade = $this->grade($evaluation, [
            [$standard->getQuestions()->first(), 15.0],
            [$malus->getQuestions()->first(), 3.0],
        ]);

        self::assertSame(12.0, $this->calculator->computeRubricTotal($grade));
    }

    /** Malus can cancel a grade; it never puts it into debt. */
    public function testTheTotalNeverGoesBelowZero(): void
    {
        $evaluation = $this->evaluation();
        $standard = $this->section($evaluation, RubricSectionKind::Standard, [20.0]);
        $malus = $this->section($evaluation, RubricSectionKind::Malus, [8.0]);

        $grade = $this->grade($evaluation, [
            [$standard->getQuestions()->first(), 5.0],
            [$malus->getQuestions()->first(), 8.0],
        ]);

        self::assertSame(0.0, $this->calculator->computeRubricTotal($grade));
    }

    /**
     * The reference is read off the standard bands alone: a barème built to add up to 20 still adds
     * up to 20 once a bonus and a malus hang under it, which is what the footer counter promises.
     */
    public function testTheReferenceIgnoresBothSpecialBands(): void
    {
        $evaluation = $this->evaluation();
        $this->section($evaluation, RubricSectionKind::Standard, [8.0, 12.0]);
        $this->section($evaluation, RubricSectionKind::Bonus, [2.0]);
        $this->section($evaluation, RubricSectionKind::Malus, [4.0]);

        self::assertSame(20.0, $evaluation->getRubricReferencePoints());
        self::assertCount(1, $evaluation->getStandardRubricSections());
        self::assertSame(2.0, $evaluation->getRubricSectionOfKind(RubricSectionKind::Bonus)?->getMaxPoints());
        self::assertSame(4.0, $evaluation->getRubricSectionOfKind(RubricSectionKind::Malus)?->getMaxPoints());
    }

    /**
     * An evaluation with nothing entered stays empty rather than showing 0 - including one whose
     * only filled boxes are malus, which is the case where a 0 would be a real statement.
     */
    public function testAnUntouchedGradeHasNoTotalAtAll(): void
    {
        $evaluation = $this->evaluation();
        $this->section($evaluation, RubricSectionKind::Standard, [20.0]);

        self::assertNull($this->calculator->computeRubricTotal($this->grade($evaluation, [])));
    }

    /** The builder files the two bands last, whatever order the form sent them in. */
    public function testTheBuilderAppendsBonusThenMalusUnderTheNamedParts(): void
    {
        $evaluation = $this->evaluation();

        (new EvaluationRubricBuilder($this->createStub(EntityManagerInterface::class)))->rebuild(
            $evaluation,
            [['name' => 'Partie 1', 'questions' => [['label' => '1', 'maxPoints' => '20']]]],
            [['label' => 'Présentation soignée', 'maxPoints' => '2']],
            [['label' => 'Retard', 'maxPoints' => '4']],
        );

        $kinds = array_map(
            static fn (EvaluationRubricSection $section): string => $section->getKind()->value,
            $evaluation->getRubricSections()->toArray(),
        );

        self::assertSame(['standard', 'bonus', 'malus'], array_values($kinds));
        self::assertSame(20.0, $evaluation->getRubricReferencePoints());
        // A bonus band carries no name: the screens label it from its kind, so nothing French ever
        // reaches the database here.
        self::assertSame('', $evaluation->getRubricSectionOfKind(RubricSectionKind::Bonus)?->getName());
    }

    /** No bonus and no malus submitted means no band at all, not two empty headings. */
    public function testTheBuilderCreatesNoBandWhenNothingWasSubmitted(): void
    {
        $evaluation = $this->evaluation();

        (new EvaluationRubricBuilder($this->createStub(EntityManagerInterface::class)))->rebuild(
            $evaluation,
            [['name' => 'Partie 1', 'questions' => [['label' => '1', 'maxPoints' => '20']]]],
        );

        self::assertCount(1, $evaluation->getRubricSections());
        self::assertNull($evaluation->getRubricSectionOfKind(RubricSectionKind::Bonus));
        self::assertNull($evaluation->getRubricSectionOfKind(RubricSectionKind::Malus));
    }

    private function evaluation(): Evaluation
    {
        return new Evaluation($this->createStub(Topic::class), 'Devoir', new \DateTimeImmutable('2026-09-17'));
    }

    /** @param list<float> $maxPoints */
    private function section(Evaluation $evaluation, RubricSectionKind $kind, array $maxPoints): EvaluationRubricSection
    {
        $section = new EvaluationRubricSection($kind->isStandard() ? 'Partie' : '', $evaluation->getRubricSections()->count(), $kind);
        foreach ($maxPoints as $index => $max) {
            $section->addQuestion(new EvaluationRubricQuestion((string) ($index + 1), $max, $index));
        }
        $evaluation->addRubricSection($section);

        return $section;
    }

    /** @param list<array{0: EvaluationRubricQuestion|false, 1: float}> $awarded */
    private function grade(Evaluation $evaluation, array $awarded): Grade
    {
        $grade = new Grade($evaluation, $this->createStub(User::class));
        foreach ($awarded as [$question, $points]) {
            self::assertInstanceOf(EvaluationRubricQuestion::class, $question);
            $grade->addRubricAnswer((new GradeRubricAnswer($grade, $question))->setPointsAwarded($points));
        }

        return $grade;
    }
}
