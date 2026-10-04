<?php

declare(strict_types=1);

namespace App\Tests\Service\Ecf;

use App\Entity\EcfActivity;
use App\Entity\EcfBooklet;
use App\Entity\EcfEvaluationRow;
use App\Entity\EcfVisa;
use App\Entity\User;
use App\Enum\EcfActivityState;
use App\Enum\EcfPart;
use App\Enum\EcfResult;
use App\Enum\EcfVisaSlot;
use App\Service\Ecf\EcfMastery;
use PHPUnit\Framework\TestCase;

/**
 * Nobody ticks « maîtrisée »: the last signed part decides (R8). A result typed but not signed
 * decides nothing; a signed complementary page overrides the main sheet.
 */
class EcfMasteryTest extends TestCase
{
    private EcfBooklet $booklet;

    protected function setUp(): void
    {
        $this->booklet = new EcfBooklet(new User('candidate'), 'TP-01281', '04');
    }

    private function sign(EcfActivity $activity, EcfPart $part): void
    {
        new EcfVisa($this->booklet, $activity, $part, EcfVisaSlot::Evaluator1, new User('admin'), 'Morel', new \DateTimeImmutable('2025-02-07'), new \DateTimeImmutable());
    }

    public function testNoActivityAndEmptyActivityAreToFill(): void
    {
        self::assertSame(EcfActivityState::ToFill, (new EcfMastery())->state($this->booklet, null));
        $activity = new EcfActivity($this->booklet, 'AT1');
        new EcfEvaluationRow($activity, EcfPart::Main, 1);
        self::assertSame(EcfActivityState::ToFill, (new EcfMastery())->state($this->booklet, $activity));
    }

    public function testAnUnsignedResultIsOnlyInProgress(): void
    {
        $activity = (new EcfActivity($this->booklet, 'AT1'))->setResult(EcfResult::Satisfied);

        self::assertSame(EcfActivityState::InProgress, (new EcfMastery())->state($this->booklet, $activity));
        self::assertNull((new EcfMastery())->isMastered($this->booklet, $activity));
    }

    public function testTheSignedMainSheetDecides(): void
    {
        $activity = (new EcfActivity($this->booklet, 'AT1'))->setResult(EcfResult::NotSatisfied);
        $this->sign($activity, EcfPart::Main);

        self::assertSame(EcfActivityState::NotSatisfied, (new EcfMastery())->state($this->booklet, $activity));
        self::assertFalse((new EcfMastery())->isMastered($this->booklet, $activity));
    }

    public function testASignedComplementaryPageOverridesTheMainSheet(): void
    {
        $activity = (new EcfActivity($this->booklet, 'AT1'))->setResult(EcfResult::NotSatisfied)->setComplementaryResult(EcfResult::Satisfied);
        $this->sign($activity, EcfPart::Main);
        self::assertFalse((new EcfMastery())->isMastered($this->booklet, $activity), 'unsigned complementary page decides nothing');

        $this->sign($activity, EcfPart::Complementary);
        self::assertSame(EcfActivityState::Satisfied, (new EcfMastery())->state($this->booklet, $activity));
        self::assertTrue((new EcfMastery())->isMastered($this->booklet, $activity));
    }

    public function testVisasAreReadPerActivityAndPart(): void
    {
        $at1 = new EcfActivity($this->booklet, 'AT1');
        $at2 = new EcfActivity($this->booklet, 'AT2');
        $this->sign($at1, EcfPart::Main);

        self::assertCount(1, EcfMastery::visasOf($this->booklet, $at1, EcfPart::Main));
        self::assertSame([], EcfMastery::visasOf($this->booklet, $at2, EcfPart::Main));
        self::assertSame([], EcfMastery::visasOf($this->booklet, $at1, EcfPart::Complementary));
        self::assertSame([], EcfMastery::visasOf($this->booklet, null, EcfPart::Synthesis));
    }
}
