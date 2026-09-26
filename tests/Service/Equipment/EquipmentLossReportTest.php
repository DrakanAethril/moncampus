<?php

declare(strict_types=1);

namespace App\Tests\Service\Equipment;

use App\Service\Equipment\EquipmentLossReport;
use PHPUnit\Framework\TestCase;

/**
 * The annual report of Gestion > Matériel: what was lost over a school year, crossed with why.
 *
 * Two rules worth pinning. A loss answered later (a mouse found, a headset repaired) leaves the
 * year it was declared in - even when it turns up the next year - because the report measures what
 * was really lost. And the value is the net quantity at the type's unit price, a type without a
 * price counting its pieces but adding nothing to the value rather than guessing one.
 */
class EquipmentLossReportTest extends TestCase
{
    public function testLossesAreNetOfWhatWasFoundOrRepaired(): void
    {
        $report = (new EquipmentLossReport())->build([
            $this->row('missing', 3, resolved: 1, cause: 'theft', type: 'Souris', price: '10.00'),
            $this->row('out_of_order', 2, resolved: 2, cause: 'breakdown', type: 'Casques', price: '30.00'),
            $this->row('out_of_order', 1, resolved: 0, cause: 'accident', type: 'Casques', price: '30.00'),
        ], new \DateTimeImmutable('2025-09-01'));

        self::assertSame(2, $report['totals']['missing']);
        self::assertSame(1, $report['totals']['outOfOrder']);
        self::assertSame(3, $report['totals']['net']);
        self::assertSame(3, $report['totals']['answered'], 'one mouse found, two headsets repaired');
        self::assertEqualsWithDelta(50.0, $report['totals']['value'], 0.001, '2 mice at 10 + 1 headset at 30');
    }

    public function testTheReportCrossesKindWithCause(): void
    {
        $report = (new EquipmentLossReport())->build([
            $this->row('missing', 5, cause: 'theft'),
            $this->row('missing', 4, cause: 'unknown'),
            $this->row('out_of_order', 7, cause: 'breakdown'),
        ], new \DateTimeImmutable('2025-09-01'));

        self::assertSame(['theft' => 5, 'unknown' => 4], $report['byCause']['missing']);
        self::assertSame(['breakdown' => 7], $report['byCause']['out_of_order']);
    }

    public function testATypeWithoutAPriceCountsButAddsNoValue(): void
    {
        $report = (new EquipmentLossReport())->build([
            $this->row('missing', 2, type: 'Câbles', price: null),
        ], new \DateTimeImmutable('2025-09-01'));

        self::assertSame(2, $report['totals']['net']);
        self::assertSame(0.0, $report['totals']['value']);
        self::assertTrue($report['byType'][0]['unpriced']);
    }

    public function testRoomsCategoriesAndMonthsAreRead(): void
    {
        $report = (new EquipmentLossReport())->build([
            $this->row('missing', 1, room: 'B12', category: 'Périphériques', at: '2025-10-03'),
            $this->row('missing', 2, room: 'B12', category: 'Périphériques', at: '2026-03-15'),
            $this->row('out_of_order', 1, room: null, category: null, at: '2026-03-20'),
        ], new \DateTimeImmutable('2025-09-01'));

        self::assertSame([['label' => 'B12', 'net' => 3], ['label' => null, 'net' => 1]], array_map(
            static fn (array $line): array => ['label' => $line['label'], 'net' => $line['net']],
            $report['byRoom'],
        ));
        self::assertSame(3, $report['byCategory'][0]['net']);
        self::assertSame('Périphériques', $report['byCategory'][0]['label']);

        // Twelve months from September; October is the second, March the seventh.
        self::assertCount(12, $report['byMonth']);
        self::assertSame(1, $report['byMonth'][1]['net']);
        self::assertSame(3, $report['byMonth'][6]['net']);
        self::assertSame('2026-03', $report['byMonth'][6]['month']);
    }

    /**
     * What the count found missing is a loss too, but it is kept apart from what somebody declared:
     * nobody saw it go, and it carries no cause.
     */
    public function testInventoryGapsAreCountedApartFromDeclaredLosses(): void
    {
        $report = (new EquipmentLossReport())->build([
            $this->row('missing', 2, cause: 'theft', type: 'Souris', price: '10.00'),
            $this->row('inventory_shortage', 3, resolved: 1, cause: null, type: 'Souris', price: '10.00'),
        ], new \DateTimeImmutable('2025-09-01'));

        self::assertSame(2, $report['totals']['missing']);
        self::assertSame(2, $report['totals']['gap'], 'three not found, one found since');
        self::assertSame(4, $report['totals']['net']);
        self::assertEqualsWithDelta(40.0, $report['totals']['value'], 0.001);
        self::assertSame(['theft' => 2], $report['byCause']['missing']);
        self::assertArrayNotHasKey('inventory_shortage', $report['byCause'], 'a gap has no cause to break down');
        self::assertSame(2, $report['byType'][0]['gap']);
    }

    /** A fully answered incident vanishes from every breakdown, not only from the totals. */
    public function testAFullyAnsweredIncidentWeighsNothingAnywhere(): void
    {
        $report = (new EquipmentLossReport())->build([
            $this->row('missing', 1, resolved: 1, room: 'C4', type: 'Webcam'),
        ], new \DateTimeImmutable('2025-09-01'));

        self::assertSame(0, $report['totals']['net']);
        self::assertSame([], $report['byType']);
        self::assertSame([], $report['byRoom']);
    }

    /**
     * @return array{kind: string, quantity: int, resolved: int, cause: string|null, occurredAt: \DateTimeImmutable, typeId: int, typeName: string, unitPrice: string|null, categoryName: string|null, roomName: string|null}
     */
    private function row(
        string $kind,
        int $quantity,
        int $resolved = 0,
        ?string $cause = 'unknown',
        string $type = 'Souris',
        ?string $price = '10.00',
        ?string $room = null,
        ?string $category = null,
        string $at = '2025-11-10',
    ): array {
        return [
            'kind' => $kind,
            'quantity' => $quantity,
            'resolved' => $resolved,
            'cause' => $cause,
            'occurredAt' => new \DateTimeImmutable($at),
            'typeId' => crc32($type),
            'typeName' => $type,
            'unitPrice' => $price,
            'categoryName' => $category,
            'roomName' => $room,
        ];
    }
}
