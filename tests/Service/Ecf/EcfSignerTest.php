<?php

declare(strict_types=1);

namespace App\Tests\Service\Ecf;

use App\Entity\Cohort;
use App\Entity\EcfActivity;
use App\Entity\EcfBooklet;
use App\Entity\EcfEvaluationRow;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\SkillGroup;
use App\Entity\User;
use App\Enum\EcfPart;
use App\Enum\EcfResult;
use App\Enum\EcfVisaSlot;
use App\Service\Ecf\EcfActivityType;
use App\Service\Ecf\EcfMastery;
use App\Service\Ecf\EcfRefusal;
use App\Service\Ecf\EcfSigner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A visa is the signer's own, dated by the server, given to a complete part, in slot order, and
 * freezes the labels it signed. Taking visas back is refused while something signed later rests on
 * them. The representative's visa closes the booklet.
 */
class EcfSignerTest extends TestCase
{
    private EcfBooklet $booklet;
    private EcfActivityType $type;
    private User $admin;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->booklet = new EcfBooklet(new User('candidate'), 'TP-01281', '04');
        $program = new Program('CDA 2', 'CDA2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        $this->type = new EcfActivityType(1, 'AT1', 'Développer une application sécurisée', ['Environnement', 'Interfaces'], new SkillGroup('Développer', $program));
        $this->admin = (new User('admin'))->setLastname('Morel');
        $this->now = new \DateTimeImmutable('2026-10-04 10:00:00');
    }

    private function signer(): EcfSigner
    {
        return new EcfSigner($this->createStub(EntityManagerInterface::class), new EcfMastery());
    }

    private function completeActivity(EcfResult $result = EcfResult::Satisfied, string $code = 'AT1'): EcfActivity
    {
        $activity = (new EcfActivity($this->booklet, $code))->setResult($result);
        (new EcfEvaluationRow($activity, EcfPart::Main, 1))->setDescription('Installation de Docker')->setEvaluatedOn(new \DateTimeImmutable('2025-02-07'))->setCompetences([1]);

        return $activity;
    }

    private function refusalOf(callable $call): string
    {
        try {
            $call();
        } catch (EcfRefusal $refusal) {
            return $refusal->key;
        }
        self::fail('Expected a refusal.');
    }

    public function testSignsACompletePartWithTheServerClockAndFreezesTheLabels(): void
    {
        $activity = $this->completeActivity();

        $visa = $this->signer()->sign($this->booklet, $this->type, EcfPart::Main, EcfVisaSlot::Evaluator1, $this->admin, new \DateTimeImmutable('2025-02-07'), $this->now);

        self::assertSame('Morel', $visa->getSignerName());
        self::assertSame($this->now, $visa->getSignedAt());
        self::assertSame('2025-02-07', $visa->getEvaluatedOn()->format('Y-m-d'));
        self::assertSame('Développer une application sécurisée', $activity->getFrozenLabel());
        self::assertSame(['Environnement', 'Interfaces'], $activity->getFrozenCompetences());
    }

    public function testRefusesAnIncompletePart(): void
    {
        $activity = new EcfActivity($this->booklet, 'AT1');
        new EcfEvaluationRow($activity, EcfPart::Main, 1);
        self::assertSame('ecfMissingRowFieldsMessage', $this->refusalOf(fn () => $this->signer()->sign($this->booklet, $this->type, EcfPart::Main, EcfVisaSlot::Evaluator1, $this->admin, $this->now, $this->now)));

        $notSatisfied = $this->completeActivity(EcfResult::NotSatisfied, 'AT2');
        $type2 = new EcfActivityType(2, 'AT2', 'Concevoir', [], $this->type->group);
        self::assertSame('ecfMissingNotSatisfiedMessage', $this->refusalOf(fn () => $this->signer()->sign($this->booklet, $type2, EcfPart::Main, EcfVisaSlot::Evaluator1, $this->admin, $this->now, $this->now)));
        $notSatisfied->setReassessCompetences([2]);
        $this->signer()->sign($this->booklet, $type2, EcfPart::Main, EcfVisaSlot::Evaluator1, $this->admin, $this->now, $this->now);
    }

    public function testRefusesAFutureEvaluationDateASecondSlotFirstAndTheSamePersonTwice(): void
    {
        $this->completeActivity();
        self::assertSame('ecfRefusalFutureDateMessage', $this->refusalOf(fn () => $this->signer()->sign($this->booklet, $this->type, EcfPart::Main, EcfVisaSlot::Evaluator1, $this->admin, new \DateTimeImmutable('2026-10-05'), $this->now)));
        self::assertSame('ecfRefusalSlotOrderMessage', $this->refusalOf(fn () => $this->signer()->sign($this->booklet, $this->type, EcfPart::Main, EcfVisaSlot::Evaluator2, $this->admin, $this->now, $this->now)));

        $this->signer()->sign($this->booklet, $this->type, EcfPart::Main, EcfVisaSlot::Evaluator1, $this->admin, $this->now, $this->now);
        self::assertSame('ecfRefusalAlreadySignedMessage', $this->refusalOf(fn () => $this->signer()->sign($this->booklet, $this->type, EcfPart::Main, EcfVisaSlot::Evaluator2, $this->admin, $this->now, $this->now)));
        self::assertSame('ecfRefusalSlotTakenMessage', $this->refusalOf(fn () => $this->signer()->sign($this->booklet, $this->type, EcfPart::Main, EcfVisaSlot::Evaluator1, new User('other'), $this->now, $this->now)));
        $this->signer()->sign($this->booklet, $this->type, EcfPart::Main, EcfVisaSlot::Evaluator2, new User('other'), $this->now, $this->now);
        self::assertSame('ecfRefusalSlotMessage', $this->refusalOf(fn () => $this->signer()->sign($this->booklet, $this->type, EcfPart::Main, EcfVisaSlot::Representative, new User('third'), $this->now, $this->now)));
    }

    public function testSynthesisWaitsForEveryActivityAndTheRepresentativeClosesTheBooklet(): void
    {
        $this->completeActivity();
        self::assertSame('ecfMissingActivitiesMessage', $this->refusalOf(fn () => $this->signer()->sign($this->booklet, null, EcfPart::Synthesis, EcfVisaSlot::Evaluator1, $this->admin, $this->now, $this->now, [$this->type])));

        $this->signer()->sign($this->booklet, $this->type, EcfPart::Main, EcfVisaSlot::Evaluator1, $this->admin, $this->now, $this->now);
        $this->signer()->sign($this->booklet, null, EcfPart::Synthesis, EcfVisaSlot::Evaluator1, $this->admin, $this->now, $this->now, [$this->type]);
        self::assertFalse($this->booklet->isClosed());

        // The organisation's representative may be the evaluator who just signed.
        $this->signer()->sign($this->booklet, null, EcfPart::Synthesis, EcfVisaSlot::Representative, $this->admin, $this->now, $this->now, [$this->type]);
        self::assertTrue($this->booklet->isClosed());
        self::assertSame('ecfRefusalClosedMessage', $this->refusalOf(fn () => $this->signer()->sign($this->booklet, null, EcfPart::Synthesis, EcfVisaSlot::Evaluator2, new User('late'), $this->now, $this->now, [$this->type])));
    }

    public function testUnsignTakesEveryVisaOfThePartBackInOrder(): void
    {
        $activity = $this->completeActivity(EcfResult::NotSatisfied);
        $activity->setReassessCompetences([1])->setComplementaryResult(EcfResult::Satisfied);
        (new EcfEvaluationRow($activity, EcfPart::Complementary, 1))->setDescription('Reprise')->setEvaluatedOn(new \DateTimeImmutable('2025-03-01'))->setCompetences([1]);
        $this->signer()->sign($this->booklet, $this->type, EcfPart::Main, EcfVisaSlot::Evaluator1, $this->admin, $this->now, $this->now);
        $this->signer()->sign($this->booklet, $this->type, EcfPart::Complementary, EcfVisaSlot::Evaluator1, $this->admin, $this->now, $this->now);
        $this->signer()->sign($this->booklet, null, EcfPart::Synthesis, EcfVisaSlot::Evaluator1, $this->admin, $this->now, $this->now, [$this->type]);

        self::assertSame('ecfRefusalSynthesisSignedMessage', $this->refusalOf(fn () => $this->signer()->unsign($this->booklet, $activity, EcfPart::Main)));
        $this->signer()->unsign($this->booklet, null, EcfPart::Synthesis);
        self::assertSame('ecfRefusalComplementarySignedMessage', $this->refusalOf(fn () => $this->signer()->unsign($this->booklet, $activity, EcfPart::Main)));
        $this->signer()->unsign($this->booklet, $activity, EcfPart::Complementary);
        self::assertTrue($activity->isFrozen(), 'the main sheet is still signed');
        $this->signer()->unsign($this->booklet, $activity, EcfPart::Main);
        self::assertFalse($activity->isFrozen());
        self::assertCount(0, $this->booklet->getVisas());
    }
}
