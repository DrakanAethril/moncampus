<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxmox;

use App\Service\Proxmox\ProxmoxGuest;
use App\Service\Proxmox\ProxmoxNode;
use App\Service\Proxmox\ProxmoxResponse;
use App\Service\Proxmox\ProxmoxTask;
use App\Service\Proxmox\ProxmoxUnavailableException;
use PHPUnit\Framework\TestCase;

/**
 * Proxmox is loose about types, and this is where that stops.
 *
 * The payloads below are the real shapes: `/cluster/resources` sends `template` as `1`, memory as
 * an integer on one endpoint and as a numeric string on another, and simply omits an option that is
 * unset rather than sending null. Every reader therefore has to answer for the absent key as much
 * as for the present one - which is what the design means by "declare only the keys you read, all
 * of them optional".
 */
class ProxmoxResponseTest extends TestCase
{
    public function testTheEnvelopeIsUnwrapped(): void
    {
        self::assertSame('8.3.2', ProxmoxResponse::fromJson('{"data":{"version":"8.3.2"}}')->string('version'));
    }

    public function testABodyWithoutADataKeyIsRefused(): void
    {
        $this->expectException(ProxmoxUnavailableException::class);
        ProxmoxResponse::fromJson('{"errors":{"vmid":"nope"}}');
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $this->expectException(ProxmoxUnavailableException::class);
        ProxmoxResponse::fromJson('<html>502 Bad Gateway</html>');
    }

    public function testANullDataIsAValidAnswer(): void
    {
        // Every successful action endpoint that has nothing to say answers exactly this.
        $response = ProxmoxResponse::fromJson('{"data":null}');

        self::assertSame([], $response->rows());
        self::assertSame([], $response->row());
        self::assertNull($response->scalar());
    }

    public function testAScalarDataIsReadAsAString(): void
    {
        $upid = 'UPID:pve1:00001A2B:0000FFFF:66C0:qmstart:204:root@pam:';

        self::assertSame($upid, ProxmoxResponse::fromJson(json_encode(['data' => $upid], \JSON_THROW_ON_ERROR))->scalar());
    }

    public function testAMissingKeyFallsBackRatherThanThrowing(): void
    {
        $response = ProxmoxResponse::fromData(['node' => 'pve1']);

        self::assertSame('', $response->string('absent'));
        self::assertSame('n/a', $response->string('absent', 'n/a'));
        self::assertNull($response->nullableString('absent'));
        self::assertSame(0, $response->int('absent'));
        self::assertNull($response->nullableInt('absent'));
        self::assertFalse($response->bool('absent'));
    }

    public function testNumbersSentAsStringsAreStillNumbers(): void
    {
        $response = ProxmoxResponse::fromData(['maxmem' => '8589934592', 'cpu' => '0.0312', 'template' => '1']);

        self::assertSame(8589934592, $response->int('maxmem'));
        self::assertEqualsWithDelta(0.0312, $response->float('cpu'), 0.00001);
        self::assertTrue($response->bool('template'));
    }

    public function testZeroIsFalseAndOneIsTrue(): void
    {
        self::assertFalse(ProxmoxResponse::fromData(['template' => 0])->bool('template'));
        self::assertTrue(ProxmoxResponse::fromData(['template' => 1])->bool('template'));
    }

    public function testRowsSkipAnythingThatIsNotAnObject(): void
    {
        self::assertCount(1, ProxmoxResponse::fromData([['node' => 'pve1'], 'stray', 42])->rows());
    }

    public function testAClusterResourcesRowBecomesAGuest(): void
    {
        $guest = ProxmoxGuest::fromRow([
            'vmid' => 204,
            'name' => 'srv-tp-04',
            'node' => 'pve1',
            'type' => 'qemu',
            'status' => 'running',
            'template' => 0,
            'pool' => 'moncampus',
            'maxcpu' => 2,
            'cpu' => 0.0312,
            'maxmem' => 4294967296,
            'mem' => 1073741824,
            'maxdisk' => 34359738368,
            'uptime' => 8123,
        ]);

        self::assertSame(204, $guest->vmid);
        self::assertSame('srv-tp-04', $guest->name);
        self::assertFalse($guest->template);
        self::assertTrue($guest->isRunning());
        self::assertFalse($guest->isContainer());
        self::assertSame('qemu', $guest->endpointSegment());
        self::assertFalse($guest->isLocked());
        self::assertEqualsWithDelta(25.0, $guest->memoryPercent(), 0.001);
    }

    public function testAContainerRowIsRecognisedAsOne(): void
    {
        $guest = ProxmoxGuest::fromRow(['vmid' => 310, 'type' => 'lxc', 'node' => 'pve2', 'status' => 'stopped']);

        self::assertTrue($guest->isContainer());
        self::assertSame('lxc', $guest->endpointSegment());
        self::assertFalse($guest->isRunning());
    }

    public function testAGuestWithNoNameFallsBackToItsVmid(): void
    {
        self::assertSame('207', ProxmoxGuest::fromRow(['vmid' => 207, 'type' => 'qemu'])->name);
    }

    public function testATemplateRowIsFlagged(): void
    {
        self::assertTrue(ProxmoxGuest::fromRow(['vmid' => 900, 'type' => 'qemu', 'template' => 1])->template);
    }

    public function testALockedGuestIsFlagged(): void
    {
        self::assertTrue(ProxmoxGuest::fromRow(['vmid' => 204, 'type' => 'qemu', 'lock' => 'clone'])->isLocked());
    }

    public function testANodeRowBecomesANode(): void
    {
        $node = ProxmoxNode::fromRow([
            'node' => 'pve1',
            'status' => 'online',
            'maxcpu' => 16,
            'cpu' => 0.12,
            'maxmem' => 68719476736,
            'mem' => 34359738368,
            'uptime' => 912345,
        ]);

        self::assertSame('pve1', $node->name);
        self::assertTrue($node->isOnline());
        self::assertEqualsWithDelta(50.0, $node->memoryPercent(), 0.001);
    }

    public function testAnEmptyNodeDoesNotDivideByZero(): void
    {
        self::assertSame(0.0, ProxmoxNode::fromRow(['node' => 'pve1'])->memoryPercent());
    }

    public function testATaskIsOnlyJudgedOnceItHasStopped(): void
    {
        $running = ProxmoxTask::fromRow(['status' => 'running'], 'UPID:pve1:x');

        self::assertFalse($running->isFinished());
        self::assertFalse($running->isSuccess());
        self::assertNull($running->failure(), 'a running task has not failed');
    }

    public function testAStoppedTaskWithExitStatusOkSucceeded(): void
    {
        $task = ProxmoxTask::fromRow(['status' => 'stopped', 'exitstatus' => 'OK'], 'UPID:pve1:x');

        self::assertTrue($task->isSuccess());
        self::assertNull($task->failure());
    }

    public function testAStoppedTaskCarriesProxmoxOwnWordingAsItsFailure(): void
    {
        $task = ProxmoxTask::fromRow(
            ['status' => 'stopped', 'exitstatus' => 'unable to find configuration file'],
            'UPID:pve1:x',
        );

        self::assertFalse($task->isSuccess());
        self::assertSame('unable to find configuration file', $task->failure());
    }

    public function testAStoppedTaskWithNoExitStatusStillFails(): void
    {
        self::assertNotNull(ProxmoxTask::fromRow(['status' => 'stopped'], 'UPID:pve1:x')->failure());
    }
}
