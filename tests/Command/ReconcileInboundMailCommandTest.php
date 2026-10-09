<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ReconcileInboundMailCommand;
use App\Enum\InboundMailOutcome;
use App\Repository\EmailMessageRepository;
use App\Service\InboundMailProcessor;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * When the nightly reconciliation rings Discord, and when it must not.
 *
 * Its alert says « the queue lost a mail ». It is only worth reading if it is silent every night
 * the queue lost nothing - and an object the inbound path read and deliberately did not store (its
 * Message-ID is already in the database) has no row under its key, so it comes back at every pass
 * for as long as the scanned window holds it.
 */
class ReconcileInboundMailCommandTest extends TestCase
{
    public function testAMailTheQueueNeverDeliveredIsRecoveredAndReported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('the queue never delivered'),
            ['replayed' => 1],
        );

        $tester = $this->reconcile(['incoming/lost' => InboundMailOutcome::Stored], $logger);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 rejoué(s)', $tester->getDisplay());
    }

    public function testAnObjectTheInboundPathChoseNotToStoreIsNotALoss(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $tester = $this->reconcile([
            'incoming/copy-of-a-send' => InboundMailOutcome::DuplicateMessageId,
            'incoming/stored-meanwhile' => InboundMailOutcome::AlreadyStored,
        ], $logger);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('0 rejoué(s)', $tester->getDisplay());
        self::assertStringContainsString('2 déjà connu(s)', $tester->getDisplay());
        self::assertStringContainsString('incoming/copy-of-a-send', $tester->getDisplay());
    }

    public function testOnlyTheRecoveredMailsAreCounted(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::anything(), ['replayed' => 1]);

        $this->reconcile([
            'incoming/lost' => InboundMailOutcome::Stored,
            'incoming/copy-of-a-send' => InboundMailOutcome::DuplicateMessageId,
        ], $logger);
    }

    /**
     * @param array<string, InboundMailOutcome> $objects what processing each key of the bucket answers
     */
    private function reconcile(array $objects, LoggerInterface $logger): CommandTester
    {
        $s3 = $this->createStub(S3Client::class);
        $s3->method('getPaginator')->willReturn([
            ['Contents' => array_map(static fn (string $key): array => ['Key' => $key], array_keys($objects))],
        ]);

        // No row under any of these keys: that is what makes the command look at them at all.
        $messages = $this->createStub(EmailMessageRepository::class);
        $messages->method('findOneBySourceKey')->willReturn(null);

        $processor = $this->createStub(InboundMailProcessor::class);
        $processor->method('process')->willReturnCallback(static fn (string $key): InboundMailOutcome => $objects[$key]);

        $command = new ReconcileInboundMailCommand(
            $s3,
            $processor,
            $messages,
            $this->createStub(EntityManagerInterface::class),
            $logger,
            'mail-bucket',
        );
        $command->setSharedLockFactory(new LockFactory(new InMemoryStore()));

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
