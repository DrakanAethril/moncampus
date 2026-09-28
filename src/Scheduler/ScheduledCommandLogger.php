<?php

declare(strict_types=1);

namespace App\Scheduler;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * One log line per scheduled task, in place of the /var/log file each cron line appended to.
 *
 * A task that exits 0 is logged at notice: the worker runs with `-v`, so that line - and nothing
 * chattier - is what `docker compose logs worker` shows. A task that exits non-zero is logged at
 * error with the tail of its output, which is what sends it to Discord: the exit code says that
 * something failed, the output says what, and the one without the other is an alert nobody can act
 * on.
 *
 * A command that throws is not this listener's business: the exception reaches Messenger, which
 * logs it at critical level on its own.
 */
#[WithMonologChannel('scheduler')]
#[AsEventListener]
final class ScheduledCommandLogger
{
    /** Enough to carry an error message and its context, not a whole table of results. */
    private const int OUTPUT_TAIL = 1500;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if (!$envelope->getMessage() instanceof RunCommandMessage) {
            return;
        }

        foreach ($envelope->all(HandledStamp::class) as $stamp) {
            $context = $stamp->getResult();

            if (!$context instanceof RunCommandContext) {
                continue;
            }

            $values = [
                'command' => $context->message->input,
                'exitCode' => $context->exitCode,
                'output' => $this->tail($context->output),
            ];

            if (0 === $context->exitCode) {
                $this->logger->notice('Scheduled task "{command}" exited with code {exitCode}.', $values);

                continue;
            }

            // The output goes in the message itself, not only in the context: the Discord alert
            // (App\Monolog\DiscordAlertFormatter) prints the message and never the context.
            $this->logger->error('Scheduled task "{command}" exited with code {exitCode}: {output}', $values);
        }
    }

    private function tail(string $output): string
    {
        // SymfonyStyle pads its blocks with blank lines and trailing spaces; none of it says anything.
        $lines = array_filter(array_map(rtrim(...), explode("\n", $output)), static fn (string $line): bool => '' !== trim($line));
        $text = implode("\n", $lines);

        return mb_strlen($text) > self::OUTPUT_TAIL ? '…'.mb_substr($text, -self::OUTPUT_TAIL) : $text;
    }
}
