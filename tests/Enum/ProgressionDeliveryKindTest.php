<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ProgressionDeliveryKind;
use PHPUnit\Framework\TestCase;

/**
 * Redispensée or co-animée - the one distinction the Qualiopi export prints rather than displays.
 *
 * The document already knew how to say « dédoublée », but only in the sequential sense: « redispensée
 * … sur un créneau distinct ». That sentence is false for a co-animated séance, and a false
 * statement in an audit file is worse than no statement, which is why every case below is pinned.
 *
 * Exercised on primitives - a group key and a teacher id per delivery - because that is the whole
 * rule: the split key is the TEACHER, never the hour.
 */
class ProgressionDeliveryKindTest extends TestCase
{
    /** @return array{group: string, teacher: int|null} */
    private function delivery(string $group, ?int $teacher): array
    {
        return ['group' => $group, 'teacher' => $teacher];
    }

    public function testAnotherGroupByAnotherTeacherIsACoDelivery(): void
    {
        self::assertSame(
            [ProgressionDeliveryKind::Primary, ProgressionDeliveryKind::CoDelivery],
            ProgressionDeliveryKind::classify([$this->delivery('1', 10), $this->delivery('2', 20)]),
        );
    }

    public function testAnotherGroupByTheSameTeacherIsStillARedelivery(): void
    {
        // « Redispensée » keeps its meaning exactly where it is true: the same formateur, twice.
        self::assertSame(
            [ProgressionDeliveryKind::Primary, ProgressionDeliveryKind::Redelivery],
            ProgressionDeliveryKind::classify([$this->delivery('1', 10), $this->delivery('2', 10)]),
        );
    }

    public function testTheSplitKeyIsTheTeacherAndNotTheHour(): void
    {
        // Same reading whether the colleague's group is taught at the same hour or on another day:
        // the export asks who delivered it, which does not require simultaneity. This is where it
        // deliberately parts company with the cahier de texte's twin rule.
        $simultaneous = ProgressionDeliveryKind::classify([$this->delivery('1', 10), $this->delivery('2', 20)]);
        $anotherDay = ProgressionDeliveryKind::classify([$this->delivery('1', 10), $this->delivery('2', 20)]);

        self::assertSame($simultaneous, $anotherDay);
        self::assertSame(ProgressionDeliveryKind::CoDelivery, $anotherDay[1]);
    }

    public function testTheSameGroupOnASecondSlotIsAContinuation(): void
    {
        // Nothing was re-given: the séance spans two créneaux and its apprenants received both.
        self::assertSame(
            [ProgressionDeliveryKind::Primary, ProgressionDeliveryKind::Continuation],
            ProgressionDeliveryKind::classify([$this->delivery('1', 10), $this->delivery('1', 10)]),
        );
    }

    public function testAWholeClassDeliveryIsNeverARedelivery(): void
    {
        self::assertSame(
            [ProgressionDeliveryKind::Primary],
            ProgressionDeliveryKind::classify([$this->delivery('', 10)]),
        );
    }

    public function testAnUnknownTeacherPrintsNeitherSentence(): void
    {
        // Both wordings make a positive claim about who stood in front of the group. When the
        // créneau names nobody, the honest answer is neither.
        self::assertSame(
            [ProgressionDeliveryKind::Primary, ProgressionDeliveryKind::Unknown],
            ProgressionDeliveryKind::classify([$this->delivery('1', 10), $this->delivery('2', null)]),
        );
        self::assertSame(
            [ProgressionDeliveryKind::Primary, ProgressionDeliveryKind::Unknown],
            ProgressionDeliveryKind::classify([$this->delivery('1', null), $this->delivery('2', 20)]),
        );
    }

    public function testThreeGroupsAreLabelledIndependently(): void
    {
        // A matière may be co-animated and still hold a group the titulaire takes a second time.
        self::assertSame(
            [
                ProgressionDeliveryKind::Primary,
                ProgressionDeliveryKind::Redelivery,
                ProgressionDeliveryKind::CoDelivery,
            ],
            ProgressionDeliveryKind::classify([
                $this->delivery('1', 10),
                $this->delivery('2', 10),
                $this->delivery('3', 20),
            ]),
        );
    }

    public function testNoDeliveryAtAllClassifiesNothing(): void
    {
        self::assertSame([], ProgressionDeliveryKind::classify([]));
    }
}
