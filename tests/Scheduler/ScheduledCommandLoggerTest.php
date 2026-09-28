<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Scheduler\ScheduledCommandLogger;
use App\Tests\Double\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The line a scheduled task leaves behind: a notice when it succeeded, an error - which reaches
 * Discord - when it exited non-zero, with what it said.
 */
class ScheduledCommandLoggerTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testASuccessIsANotice(): void
    {
        $this->handle('app:mail:consume-inbound', 0, "\n [OK] 3 mail(s) importé(s).   \n\n");

        self::assertCount(1, $this->logger->records);
        self::assertSame('notice', $this->logger->records[0]['level']);
        self::assertSame('app:mail:consume-inbound', $this->logger->records[0]['context']['command']);
        self::assertSame(' [OK] 3 mail(s) importé(s).', $this->logger->records[0]['context']['output']);
    }

    public function testAFailureIsAnErrorThatSaysWhat(): void
    {
        $this->handle('app:uploads:purge', 1, " [ERROR] 2 objet(s) n'ont pas pu être supprimés.\n");

        self::assertSame('error', $this->logger->records[0]['level']);
        // In the message, not only in the context: the Discord alert prints the message alone.
        self::assertStringContainsString('{output}', $this->logger->records[0]['message']);
        self::assertSame(1, $this->logger->records[0]['context']['exitCode']);
        $output = $this->logger->records[0]['context']['output'];
        self::assertIsString($output);
        self::assertStringContainsString("n'ont pas pu être supprimés", $output);
    }

    public function testALongOutputKeepsItsEnd(): void
    {
        $this->handle('app:proxmox:check', 1, str_repeat("ligne de tableau\n", 500).'hôte pve-2 injoignable');

        $output = $this->logger->records[0]['context']['output'];
        self::assertIsString($output);
        self::assertStringStartsWith('…', $output);
        self::assertStringEndsWith('hôte pve-2 injoignable', $output);
        self::assertLessThanOrEqual(1501, mb_strlen($output));
    }

    public function testAnotherMessageIsNotItsBusiness(): void
    {
        $this->listener()(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'scheduler_default'));

        self::assertSame([], $this->logger->records);
    }

    private function handle(string $command, int $exitCode, string $output): void
    {
        $message = new RunCommandMessage($command, throwOnFailure: false);
        $envelope = new Envelope($message, [new HandledStamp(new RunCommandContext($message, $exitCode, $output), 'handler')]);

        $this->listener()(new WorkerMessageHandledEvent($envelope, 'scheduler_default'));
    }

    private function listener(): ScheduledCommandLogger
    {
        return new ScheduledCommandLogger($this->logger);
    }
}
