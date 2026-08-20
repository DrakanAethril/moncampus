<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LessonLogTwinRule;
use PHPUnit\Framework\TestCase;

/**
 * The twin créneau: same class, same matière, same day, overlapping hours, a DIFFERENT teacher.
 *
 * Every clause is pinned, because each one on its own is what stops the button offering a
 * colleague's administrative register as if it were this teacher's own. Exercised on primitives -
 * the rule is a comparison of six values and nothing else.
 */
class LessonLogTwinRuleTest extends TestCase
{
    /** @return array{program: int|null, topic: string|null, day: string|null, start: string|null, end: string|null, teacher: int|null, groupCount: int} */
    private function slot(
        ?int $teacher = 1,
        ?string $day = '2027-03-19',
        ?string $start = '10:00',
        ?string $end = '12:00',
        int $groupCount = 1,
        ?int $program = 7,
        ?string $topic = 'Cybersécurité',
    ): array {
        return compact('program', 'topic', 'day', 'start', 'end', 'teacher', 'groupCount');
    }

    public function testSameHourAndAnotherTeacherIsATwin(): void
    {
        self::assertTrue(LessonLogTwinRule::matches($this->slot(teacher: 1), $this->slot(teacher: 2)));
    }

    public function testTheSameTeacherIsNotATwin(): void
    {
        // Two créneaux of one's own are the same lesson twice, not a co-animation: there is nobody
        // else's text to take back.
        self::assertFalse(LessonLogTwinRule::matches($this->slot(teacher: 1), $this->slot(teacher: 1)));
    }

    public function testAnotherDayIsNotATwin(): void
    {
        self::assertFalse(LessonLogTwinRule::matches(
            $this->slot(teacher: 1, day: '2027-03-19'),
            $this->slot(teacher: 2, day: '2027-03-26'),
        ));
    }

    public function testAWholeClassSlotIsNotATwin(): void
    {
        // A co-animated matière is split by construction; a créneau naming no group holds everybody.
        self::assertFalse(LessonLogTwinRule::matches($this->slot(teacher: 1), $this->slot(teacher: 2, groupCount: 0)));
    }

    public function testAnotherClassIsNotATwin(): void
    {
        // That is the other import's case - « les mêmes séances ailleurs » - and it keeps its own
        // entry in the menu.
        self::assertFalse(LessonLogTwinRule::matches($this->slot(teacher: 1), $this->slot(teacher: 2, program: 9)));
    }

    public function testAnotherMatiereIsNotATwin(): void
    {
        self::assertFalse(LessonLogTwinRule::matches($this->slot(teacher: 1), $this->slot(teacher: 2, topic: 'Réseaux')));
    }

    public function testHoursThatOnlyTouchAreNotSimultaneous(): void
    {
        // 10-12 then 12-14 is the colleague taking over the room afterwards, not co-animation.
        self::assertFalse(LessonLogTwinRule::matches(
            $this->slot(teacher: 1, start: '10:00', end: '12:00'),
            $this->slot(teacher: 2, start: '12:00', end: '14:00'),
        ));
    }

    public function testAPartialOverlapIsStillSimultaneous(): void
    {
        // The two timetables need not be cut identically for the lesson to be the same one.
        self::assertTrue(LessonLogTwinRule::matches(
            $this->slot(teacher: 1, start: '10:00', end: '12:00'),
            $this->slot(teacher: 2, start: '11:00', end: '13:00'),
        ));
    }

    public function testAnUnknownTeacherOnEitherSideIsNotATwin(): void
    {
        self::assertFalse(LessonLogTwinRule::matches($this->slot(teacher: 1), $this->slot(teacher: null)));
        self::assertFalse(LessonLogTwinRule::matches($this->slot(teacher: null), $this->slot(teacher: 2)));
    }

    public function testAMissingHourIsNotATwin(): void
    {
        // An incomplete créneau answers "no" rather than guessing: the cost of a wrong yes is a
        // colleague's register offered as this teacher's own.
        self::assertFalse(LessonLogTwinRule::matches($this->slot(teacher: 1, end: null), $this->slot(teacher: 2)));
        self::assertFalse(LessonLogTwinRule::matches($this->slot(teacher: 1), $this->slot(teacher: 2, start: null)));
    }
}
