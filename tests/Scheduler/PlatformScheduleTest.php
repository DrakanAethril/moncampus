<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Scheduler\PlatformSchedule;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * What the worker runs, and when.
 *
 * The first test pins the schedule as built: a task added, removed or moved fails here until this
 * list - and, by the same gesture, docs/production.md and CLAUDE.md - says so. The schedule
 * replaced a crontab that lived on the server and that nobody could read from the code; this is
 * the part of that gain a test can hold.
 */
class PlatformScheduleTest extends KernelTestCase
{
    public function testTheScheduleIsTheDocumentedOne(): void
    {
        self::bootKernel();
        $schedule = self::getContainer()->get(PlatformSchedule::class)->getSchedule();

        $actual = [];
        foreach ($schedule->getRecurringMessages() as $recurring) {
            foreach ($this->messagesOf($recurring) as $message) {
                $actual[$message->input] = (string) $recurring->getTrigger();
            }
        }

        self::assertSame([
            'app:mail:consume-inbound' => '* * * * *',
            'app:mail:consume-events' => '* * * * *',
            'app:vm-batch:advance' => '* * * * *',
            'app:ldap:apply-account-requests' => '* * * * *',
            'app:eco:read-terrain' => '* * * * *',
            'app:rncp:fetch' => '* * * * *',
            'app:proxmox:check' => '*/5 * * * *',
            'app:proxmox:scan-addresses' => '2-59/5 * * * *',
            'app:class-board:photo' => '7 * * * *',
            'app:mail:reconcile' => '30 2 * * *',
            'app:uploads:purge' => '0 3 * * *',
            'app:purge-platform-activity' => '15 3 * * *',
            'app:counters:recompute' => '45 3 * * *',
            'app:game:close-month' => '30 4 * * *',
            'app:proxmox:expire-batches' => '0 7 * * *',
            'app:rncp:check' => '30 5 * * 1',
        ], $actual);
    }

    public function testEveryTaskNamesACommandThatExists(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        foreach ($this->messages() as $message) {
            // A typo here would not fail anything at deploy time: the worker would log « command
            // not defined » once a minute, for ever.
            self::assertTrue($application->has($message->input), \sprintf('No command named "%s".', $message->input));
        }
    }

    public function testATaskReportsItsFailureInsteadOfThrowingIt(): void
    {
        // A non-zero exit is an outcome to log with the command's output (ScheduledCommandLogger),
        // not an exception Messenger would log without it.
        foreach ($this->messages() as $message) {
            self::assertFalse($message->throwOnFailure, $message->input);
        }
    }

    public function testTheNightRunsOnParisTime(): void
    {
        $schedule = self::getContainer()->get(PlatformSchedule::class)->getSchedule();

        foreach ($schedule->getRecurringMessages() as $recurring) {
            $next = $recurring->getTrigger()->getNextRunDate(new \DateTimeImmutable('2026-01-15 12:00:00', new \DateTimeZone('UTC')));
            self::assertNotNull($next);
            self::assertSame('Europe/Paris', $next->getTimezone()->getName(), (string) $recurring->getTrigger());
        }
    }

    /** @return list<RunCommandMessage> */
    private function messages(): array
    {
        self::bootKernel();
        $schedule = self::getContainer()->get(PlatformSchedule::class)->getSchedule();

        $messages = [];
        foreach ($schedule->getRecurringMessages() as $recurring) {
            array_push($messages, ...$this->messagesOf($recurring));
        }

        self::assertCount(\count(PlatformSchedule::TASKS), $messages);

        return $messages;
    }

    /** @return list<RunCommandMessage> */
    private function messagesOf(RecurringMessage $recurring): array
    {
        $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable());

        $messages = [];
        foreach ($recurring->getMessages($context) as $message) {
            self::assertInstanceOf(RunCommandMessage::class, $message);
            $messages[] = $message;
        }

        return $messages;
    }
}
