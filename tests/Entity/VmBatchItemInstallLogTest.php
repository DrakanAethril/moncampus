<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Cohort;
use App\Entity\IpRange;
use App\Entity\Program;
use App\Entity\ProxmoxHost;
use App\Entity\SchoolYear;
use App\Entity\VmBatch;
use App\Entity\VmBatchItem;
use App\Enum\VmInstallStep;
use PHPUnit\Framework\TestCase;

/**
 * The installation log of one machine: what was done to it, in order, in a single column.
 *
 * The behaviours worth pinning are the ones that only show up months later - a column that grows
 * without limit, and a screen that breaks on a row written by an older version. Neither would be
 * noticed the day the code is written.
 */
class VmBatchItemInstallLogTest extends TestCase
{
    public function testAnEmptyLogReadsAsNoStoryRatherThanAnError(): void
    {
        self::assertSame([], $this->item()->getInstallLogEntries());
    }

    public function testEachStepIsKeptInOrderWithItsDetail(): void
    {
        $item = $this->item();
        $item->appendInstallLog(VmInstallStep::AddressReserved, '10.30.0.10');
        $item->appendInstallLog(VmInstallStep::KeysInstalled, 'MonCampus, Marie Dupont — Portable');

        $entries = $item->getInstallLogEntries();

        self::assertCount(2, $entries);
        self::assertSame(VmInstallStep::AddressReserved, $entries[0]['step']);
        self::assertSame('10.30.0.10', $entries[0]['detail']);
        self::assertSame('MonCampus, Marie Dupont — Portable', $entries[1]['detail']);
        self::assertTrue($entries[1]['ok']);
    }

    /** A refusal is what the log is read for, so it has to survive the round trip as a refusal. */
    public function testAFailureIsRecordedAsOne(): void
    {
        $item = $this->item();
        $item->appendInstallLog(VmInstallStep::Unreachable, 'Could not reach 10.30.0.10:22: Connection refused', ok: false);

        $entry = $item->getInstallLogEntries()[0];

        self::assertFalse($entry['ok']);
        self::assertSame('Could not reach 10.30.0.10:22: Connection refused', $entry['detail']);
    }

    /**
     * A batch left retrying all afternoon writes a line per pass. The tail is what somebody reads
     * when a machine is stuck, so the oldest lines go rather than the newest.
     */
    public function testTheLogIsBoundedAndKeepsTheMostRecentLines(): void
    {
        $item = $this->item();

        foreach (range(1, 250) as $pass) {
            $item->appendInstallLog(VmInstallStep::Unreachable, 'attempt '.$pass, ok: false);
        }

        $entries = $item->getInstallLogEntries();

        self::assertLessThanOrEqual(200, \count($entries));
        self::assertSame('attempt 250', end($entries)['detail'], 'the tail is what matters');
    }

    /** A code written by an older version is dropped, not shown raw and never fatal. */
    public function testAStepThisVersionNoLongerKnowsIsSkipped(): void
    {
        $item = $this->item();
        $item->appendInstallLog(VmInstallStep::CloneFinished);

        $reflection = new \ReflectionProperty(VmBatchItem::class, 'installLog');
        $reflection->setValue($item, json_encode([
            ['at' => '2026-08-20T10:00:00+02:00', 'step' => 'somethingRetired', 'detail' => null, 'ok' => true],
            ['at' => '2026-08-20T10:00:01+02:00', 'step' => 'cloneFinished', 'detail' => null, 'ok' => true],
        ]));

        $entries = $item->getInstallLogEntries();

        self::assertCount(1, $entries);
        self::assertSame(VmInstallStep::CloneFinished, $entries[0]['step']);
    }

    public function testAColumnHoldingSomethingThatIsNotAListDegradesToAnEmptyStory(): void
    {
        $item = $this->item();
        (new \ReflectionProperty(VmBatchItem::class, 'installLog'))->setValue($item, 'not json at all');

        self::assertSame([], $item->getInstallLogEntries());
    }

    private function item(): VmBatchItem
    {
        $host = new ProxmoxHost('campus', '192.0.2.10', 'svc');
        $range = new IpRange('salle', $host, '10.30.0.0/24', '10.30.0.254', '10.30.0.1', '10.30.0.253');
        $program = new Program('SIO-2', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));

        return new VmBatchItem(new VmBatch('Lot', $program, $host, $range, 9001, 'pve'), 'Marie', 'poste-01', 'marie', 0);
    }
}
