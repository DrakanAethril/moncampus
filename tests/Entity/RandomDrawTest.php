<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\RandomDraw;
use App\Entity\SchoolYear;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * A saved draw of the « Tirage au sort » tool.
 *
 * Who may read or write one is ProgramToolsController's job; what the entity owes is the shape of
 * the thing being kept - a draw starts on the whole class with nobody called, the order of the
 * students already drawn is the payload rather than a detail, and a list that has been filtered
 * stays a JSON list instead of turning into an object with holes in its keys.
 */
class RandomDrawTest extends TestCase
{
    public function testANewDrawIsTheWholeClassWithNobodyCalledYet(): void
    {
        $draw = $this->draw();

        self::assertNull($draw->getOption());
        self::assertFalse($draw->isAllowRepeat());
        self::assertSame([], $draw->getDrawnStudentIds());
    }

    public function testTheOrderStudentsWereDrawnInIsKept(): void
    {
        $draw = $this->draw();

        $draw->setDrawnStudentIds([7, 3, 12]);

        self::assertSame([7, 3, 12], $draw->getDrawnStudentIds());
    }

    /**
     * A student who has left the class is filtered out of the list, which leaves a gap in its keys.
     * Written back as-is, that gap turns the JSON column into an object, and the browser then reads
     * the draws as {"0":7,"2":12} - a shape nothing on the other side expects.
     */
    public function testAFilteredListIsStoredBackAsAList(): void
    {
        $draw = $this->draw();

        $draw->setDrawnStudentIds(array_filter([7, 3, 12], static fn (int $id): bool => 3 !== $id));

        self::assertSame([7, 12], $draw->getDrawnStudentIds());
    }

    public function testTouchMovesTheUpdateStampWithoutTouchingTheCreationOne(): void
    {
        $draw = $this->draw();
        $createdAt = $draw->getCreatedAt();

        $draw->touch();

        self::assertSame($createdAt, $draw->getCreatedAt());
        self::assertGreaterThanOrEqual($createdAt, $draw->getUpdatedAt());
    }

    private function draw(): RandomDraw
    {
        $program = new Program('SIO-2 2026-2027', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));

        return new RandomDraw($program, new User('teacher'), 'Oral anglais — série 1');
    }
}
