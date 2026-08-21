<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxmox;

use App\Entity\ProxmoxOperation;
use App\Enum\ProxmoxAction;
use App\Enum\ProxmoxOperationStatus;
use App\Repository\ProxmoxOperationRepository;
use App\Service\Proxmox\ProxmoxClient;
use App\Service\Proxmox\ProxmoxOperationTracker;
use App\Service\Proxmox\ProxmoxResponse;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * When MonCampus stops believing a task, and when it is allowed to ask again.
 *
 * Both questions cost a class its machines when answered wrong, and both were answered wrong by the
 * same single five-minute number. A clone of a class's worth of disks routinely runs longer than
 * that, so every one of them was declared `unknown` - and an `unknown` creation fails its machine,
 * which is then never configured and never started: it sits on the hypervisor as a bare copy of the
 * template, carrying the template's address and no account. The clone had in fact succeeded.
 */
class ProxmoxOperationTrackerTest extends TestCase
{
    public function testACloneStillRunningAfterFiveMinutesIsStillBelieved(): void
    {
        $operation = $this->running(ProxmoxAction::Clone, durationSeconds: 900);
        $operation->expects(self::never())->method('markUnknown');

        $this->tracker()->resolve($operation, $this->answering(['status' => 'running']));
    }

    public function testAStartStillRunningAfterFiveMinutesIsNotBelieved(): void
    {
        // The other end of the same list, and the reason the ceiling belongs to the action: a start
        // that has not finished in five minutes is a start that will not finish.
        $operation = $this->running(ProxmoxAction::Start, durationSeconds: 900);
        $operation->expects(self::once())->method('markUnknown');

        $this->tracker()->resolve($operation, $this->answering(['status' => 'running']));
    }

    public function testAnUnknownOperationIsAskedAboutAgain(): void
    {
        // `unknown` is the absence of a verdict, not a verdict. Proxmox still holds the task's
        // status, so a batch resumed an hour later can learn that the clone it gave up on landed -
        // which is the difference between a machine that finishes deploying and one that never can.
        $operation = $this->createMock(ProxmoxOperation::class);
        $operation->method('getUpid')->willReturn('UPID:pve:0000:clone:');
        $operation->method('getNode')->willReturn('pve');
        $operation->method('getAction')->willReturn(ProxmoxAction::Clone);
        $operation->method('getStatus')->willReturn(ProxmoxOperationStatus::Unknown);
        $operation->expects(self::once())->method('markSucceeded');

        $this->tracker()->resolve($operation, $this->answering(['status' => 'stopped', 'exitstatus' => 'OK']));
    }

    public function testASucceededOperationIsNotAskedAboutAgain(): void
    {
        $operation = $this->createStub(ProxmoxOperation::class);
        $operation->method('getUpid')->willReturn('UPID:pve:0000:clone:');
        $operation->method('getNode')->willReturn('pve');
        $operation->method('getStatus')->willReturn(ProxmoxOperationStatus::Succeeded);

        $client = $this->createMock(ProxmoxClient::class);
        $client->expects(self::never())->method('get');

        $this->tracker()->resolve($operation, $client);
    }

    /** @param array<string, string> $row */
    private function answering(array $row): ProxmoxClient&Stub
    {
        $client = $this->createStub(ProxmoxClient::class);
        $client->method('get')->willReturn(ProxmoxResponse::fromData($row));

        return $client;
    }

    private function running(ProxmoxAction $action, int $durationSeconds): ProxmoxOperation&MockObject
    {
        $operation = $this->createMock(ProxmoxOperation::class);
        $operation->method('getUpid')->willReturn('UPID:pve:0000:task:');
        $operation->method('getNode')->willReturn('pve');
        $operation->method('getAction')->willReturn($action);
        $operation->method('getStatus')->willReturn(ProxmoxOperationStatus::Running);
        $operation->method('durationSeconds')->willReturn($durationSeconds);

        return $operation;
    }

    private function tracker(): ProxmoxOperationTracker
    {
        return new ProxmoxOperationTracker(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ProxmoxOperationRepository::class),
        );
    }
}
