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
use App\Enum\VmBatchItemStatus;
use PHPUnit\Framework\TestCase;

/**
 * What the batch screen reads to decide whether to keep refreshing itself, and which machine's log
 * to unfold.
 *
 * Both answers are the entity's rather than the template's because both have an edge that is easy
 * to get wrong by eye: a deployment is still under way in the moments when nothing at all is in
 * flight, and it is over when what is left has only refused.
 */
class VmBatchProgressTest extends TestCase
{
    public function testABatchNobodyHasDeployedIsNotUnderWay(): void
    {
        $batch = $this->batch(VmBatchItemStatus::Planned, VmBatchItemStatus::Planned);

        self::assertFalse($batch->isDeploymentUnderWay());
    }

    public function testAMachineBeingBuiltMakesItUnderWay(): void
    {
        foreach ([VmBatchItemStatus::Creating, VmBatchItemStatus::Created] as $status) {
            self::assertTrue($this->batch($status, VmBatchItemStatus::Planned)->isDeploymentUnderWay(), $status->value);
        }
    }

    /**
     * The gap the screen used to fall into: one machine at a time means there are moments when the
     * previous one is finished and the next has not been picked up yet. A page that stopped
     * refreshing there would look finished while the class was still being built.
     */
    public function testItStaysUnderWayBetweenTwoMachines(): void
    {
        $batch = $this->batch(VmBatchItemStatus::Provisioned, VmBatchItemStatus::Planned);

        self::assertTrue($batch->isDeploymentUnderWay());
    }

    public function testAFinishedBatchStopsRefreshing(): void
    {
        $batch = $this->batch(VmBatchItemStatus::Provisioned, VmBatchItemStatus::Provisioned);

        self::assertFalse($batch->isDeploymentUnderWay());
    }

    /**
     * Nothing will visibly move until somebody looks at it, and a page refreshing for ever over a
     * refusal is noise rather than information.
     */
    public function testABatchWhoseRemainderHasOnlyRefusedStopsRefreshing(): void
    {
        $batch = $this->batch(VmBatchItemStatus::Provisioned, VmBatchItemStatus::Failed);

        self::assertFalse($batch->isDeploymentUnderWay());
    }

    public function testTheUnfoldedLogIsTheFirstMachineThatIsNotFinished(): void
    {
        $batch = $this->batch(VmBatchItemStatus::Provisioned, VmBatchItemStatus::Creating, VmBatchItemStatus::Planned);

        self::assertSame('tp-02', $batch->firstUnfinishedItem()?->getGuestName());
    }

    public function testAFinishedBatchUnfoldsNothing(): void
    {
        $batch = $this->batch(VmBatchItemStatus::Provisioned, VmBatchItemStatus::Provisioned);

        self::assertNull($batch->firstUnfinishedItem());
    }

    private function batch(VmBatchItemStatus ...$statuses): VmBatch
    {
        $program = new Program('SIO-2 2026-2027', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        $batch = new VmBatch('TP', $program, $this->createStub(ProxmoxHost::class), $this->createStub(IpRange::class), 9000, 'pve');

        foreach ($statuses as $position => $status) {
            $item = new VmBatchItem($batch, \sprintf('Groupe %d', $position + 1), \sprintf('tp-%02d', $position + 1), \sprintf('groupe-%d', $position + 1), $position + 1);
            $item->setStatus($status);
            $batch->addItem($item);
        }

        return $batch;
    }
}
