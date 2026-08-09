<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Evaluation;
use App\Entity\ProgressionSequence;
use App\Enum\EvaluationNature;
use App\Service\ProgressionEvaluationSelector;
use PHPUnit\Framework\TestCase;

/**
 * Which evaluations the progression screen offers as "hors séquence" - the ones a teacher can still
 * attach to a séquence.
 *
 * Three conditions decide it and none of them is visible from the screen: an evaluation only shows
 * up if it declares a nature, if it is not already attached somewhere, and if it has not been
 * deactivated. Get any of them wrong and the list either hides work the teacher is looking for or
 * offers them an evaluation they cannot place.
 */
class ProgressionEvaluationSelectorTest extends TestCase
{
    private ProgressionEvaluationSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new ProgressionEvaluationSelector();
    }

    public function testKeepsOnlyEvaluationsThatCarryANature(): void
    {
        // Nature is what makes an evaluation placeable at all (diagnostic / formative / summative).
        $withNature = $this->evaluation('2026-03-02', EvaluationNature::Summative);
        $withoutNature = $this->evaluation('2026-03-03', null);

        self::assertSame([$withNature], $this->selector->outOfSequence([$withNature, $withoutNature]));
    }

    public function testDropsEvaluationsAlreadyAttachedToASequence(): void
    {
        $free = $this->evaluation('2026-03-02', EvaluationNature::Formative);
        $attached = $this->evaluation('2026-03-03', EvaluationNature::Formative);
        $attached->setProgressionSequence((new \ReflectionClass(ProgressionSequence::class))->newInstanceWithoutConstructor());

        self::assertSame([$free], $this->selector->outOfSequence([$free, $attached]));
    }

    public function testDropsDeactivatedEvaluations(): void
    {
        $live = $this->evaluation('2026-03-02', EvaluationNature::Summative);
        $removed = $this->evaluation('2026-03-03', EvaluationNature::Summative);
        $removed->setInactiveDate(new \DateTimeImmutable('2026-03-04'));

        self::assertSame([$live], $this->selector->outOfSequence([$live, $removed]));
    }

    public function testSortsByDateOldestFirst(): void
    {
        $late = $this->evaluation('2026-06-01', EvaluationNature::Summative);
        $early = $this->evaluation('2026-01-15', EvaluationNature::Summative);
        $middle = $this->evaluation('2026-03-20', EvaluationNature::Summative);

        self::assertSame([$early, $middle, $late], $this->selector->outOfSequence([$late, $early, $middle]));
    }

    public function testAnEvaluationWithoutADateStillComesBack(): void
    {
        // A teacher can create an evaluation before deciding when it falls; hiding it would make it
        // unreachable from this screen.
        $undated = $this->evaluation(null, EvaluationNature::Diagnostic);
        $dated = $this->evaluation('2026-03-02', EvaluationNature::Diagnostic);

        self::assertCount(2, $this->selector->outOfSequence([$dated, $undated]));
    }

    public function testNothingToOfferYieldsAnEmptyList(): void
    {
        self::assertSame([], $this->selector->outOfSequence([]));
    }

    private function evaluation(?string $date, ?EvaluationNature $nature): Evaluation
    {
        $evaluation = (new \ReflectionClass(Evaluation::class))->newInstanceWithoutConstructor();
        $evaluation->setName('éval');
        $evaluation->setNature($nature);
        $evaluation->setDate(null === $date ? null : new \DateTimeImmutable($date));

        return $evaluation;
    }
}
