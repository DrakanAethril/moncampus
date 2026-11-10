<?php

declare(strict_types=1);

namespace App\Tests\Service\SeatingPlan;

use App\Service\SeatingPlan\InvalidSeatingPlanException;
use App\Service\SeatingPlan\SeatingPlanDocument;
use App\Service\SeatingPlan\SeatingRoomTemplates;
use PHPUnit\Framework\TestCase;

/**
 * The one door a seating plan comes in by: refused whole on a rule of shape, forgiving about a
 * class that moved under it.
 */
class SeatingPlanDocumentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(array $overrides = []): array
    {
        return array_replace([
            'room' => ['columns' => 16, 'rows' => 11, 'tables' => [
                ['id' => 't1', 'type' => 'double', 'x' => 1, 'y' => 2, 'rotated' => false],
                ['id' => 't2', 'type' => 'double', 'x' => 4, 'y' => 2, 'rotated' => false],
            ]],
            'rules' => [['id' => 'r1', 'type' => 'separate', 'students' => [1, 2]]],
            'preferences' => ['mixOptions' => true, 'frontFirst' => false],
            'seats' => ['t1-0' => 1, 't1-1' => 2, 't2-0' => 3],
            'pins' => ['t1-0'],
            'seed' => 4,
        ], $overrides);
    }

    public function testAWellFormedDocumentReadsBackAsItWasWritten(): void
    {
        $document = SeatingPlanDocument::fromArray($this->document(), [1, 2, 3]);

        self::assertSame($this->document(), $document->toArray());
        self::assertCount(4, $document->room->seats());
        self::assertFalse($document->frontFirst);
    }

    public function testTwoTablesOnOneCellAreRefused(): void
    {
        $this->expectException(InvalidSeatingPlanException::class);

        SeatingPlanDocument::fromArray($this->document(['room' => ['columns' => 16, 'rows' => 11, 'tables' => [
            ['id' => 't1', 'type' => 'double', 'x' => 1, 'y' => 2],
            ['id' => 't2', 'type' => 'single', 'x' => 2, 'y' => 2],
        ]], 'seats' => [], 'pins' => []]), [1, 2, 3]);
    }

    public function testATableOutsideTheWallsIsRefused(): void
    {
        $this->expectException(InvalidSeatingPlanException::class);

        SeatingPlanDocument::fromArray($this->document(['room' => ['columns' => 16, 'rows' => 11, 'tables' => [
            ['id' => 't1', 'type' => 'island', 'x' => 15, 'y' => 2],
        ]], 'seats' => [], 'pins' => []]), [1, 2, 3]);
    }

    public function testUnknownFurnitureIsRefused(): void
    {
        $this->expectException(InvalidSeatingPlanException::class);

        SeatingPlanDocument::fromArray($this->document(['room' => ['columns' => 16, 'rows' => 11, 'tables' => [
            ['id' => 't1', 'type' => 'sofa', 'x' => 1, 'y' => 2],
        ]], 'seats' => [], 'pins' => []]), [1, 2, 3]);
    }

    public function testAStudentSeatedTwiceIsRefused(): void
    {
        $this->expectException(InvalidSeatingPlanException::class);

        SeatingPlanDocument::fromArray($this->document(['seats' => ['t1-0' => 1, 't2-1' => 1]]), [1, 2, 3]);
    }

    public function testASeatTheRoomDoesNotHaveIsRefused(): void
    {
        $this->expectException(InvalidSeatingPlanException::class);

        SeatingPlanDocument::fromArray($this->document(['seats' => ['t9-0' => 1]]), [1, 2, 3]);
    }

    public function testARuleWithTooFewStudentsIsRefused(): void
    {
        $this->expectException(InvalidSeatingPlanException::class);

        SeatingPlanDocument::fromArray($this->document(['rules' => [['id' => 'r1', 'type' => 'separate', 'students' => [1]]]]), [1, 2, 3]);
    }

    public function testAStudentWhoLeftTheClassIsDroppedEverywhere(): void
    {
        $document = SeatingPlanDocument::fromArray($this->document(), [2, 3]);

        self::assertSame(['t1-1' => 2, 't2-0' => 3], $document->seats);
        // Her lock goes with her, and the « séparer » left with one student separates nothing.
        self::assertSame([], $document->pins);
        self::assertSame([], $document->rules);
    }

    public function testASquarePieceIsNeverStoredRotated(): void
    {
        $document = SeatingPlanDocument::fromArray($this->document(['room' => ['columns' => 16, 'rows' => 11, 'tables' => [
            ['id' => 't1', 'type' => 'island', 'x' => 1, 'y' => 2, 'rotated' => true],
        ]], 'seats' => [], 'pins' => []]), [1, 2, 3]);

        self::assertFalse($document->room->tables[0]->rotated);
    }

    public function testEveryTemplateIsAValidRoom(): void
    {
        foreach ((new SeatingRoomTemplates())->all() as $key => $template) {
            $room = SeatingPlanDocument::room($template['room']->toArray());
            self::assertNotEmpty($room->seats(), $key);
        }
    }

    public function testMovingTheFurnitureDropsTheSeatsThatAreGone(): void
    {
        $document = SeatingPlanDocument::fromArray($this->document(), [1, 2, 3]);
        $smaller = SeatingPlanDocument::room(['columns' => 16, 'rows' => 11, 'tables' => [['id' => 't1', 'type' => 'double', 'x' => 1, 'y' => 2]]]);

        $moved = $document->withRoom($smaller);

        self::assertSame(['t1-0' => 1, 't1-1' => 2], $moved->seats);
        self::assertSame(['t1-0'], $moved->pins);
    }
}
