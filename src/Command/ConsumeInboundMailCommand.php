<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\InboundMailProcessor;
use Aws\Sqs\SqsClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drains the "inbound" SQS queue: every message carries the S3 key of a `.eml` dropped by SES.
 *
 * Designed to be called periodically (cron, every minute) rather than to run permanently. The
 * choice is deliberate: the real flow is counted in tens of mails per day, not per second, and a
 * minute of latency on an application arriving is invisible. In exchange there is no resident
 * process to watch, no memory leak to bound and no restart policy to tune - the command is born,
 * drains the queue, and dies.
 *
 * Why not go through Symfony Messenger: SQS already implements the semantics needed here - the
 * visibility timeout *is* the retry delay, the receive count *is* the attempt counter, the DLQ *is*
 * the failure transport, and a CloudWatch alarm watches its depth. Layering Messenger's retry
 * mechanism on top would have diverted failures to the `failed` transport in the database, leaving
 * the DLQ empty and the alarm silent.
 *
 * Hence this loop's single rule: **a message is only deleted after a successful write**. On failure
 * we do nothing, the message becomes visible again once the visibility timeout expires, and SQS
 * moves it to the DLQ on the fifth attempt. An abrupt interruption is therefore harmless, and
 * App\Service\InboundMailProcessor's idempotency prevents a duplicate on redelivery.
 */
#[AsCommand(
    name: 'app:mail:consume-inbound',
    description: 'Vide la file SQS des mails entrants du Courrier école (à appeler par cron).',
)]
class ConsumeInboundMailCommand extends Command
{
    use LockableTrait;

    /** The maximum the SQS API allows. */
    private const int BATCH_SIZE = 10;

    /**
     * Any strictly positive value turns on long polling, which queries every server of the queue: a
     * single second is therefore enough to guarantee a present message is seen. We stay low because
     * under periodic execution, every second of waiting is a second of resident PHP process paid
     * for nothing when the queue is empty.
     */
    private const int WAIT_TIME_SECONDS = 2;

    public function __construct(
        private readonly SqsClient $mailSqsClient,
        private readonly InboundMailProcessor $processor,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $inboundQueueUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Plafond de durée, en secondes, pour ne pas déborder sur l\'exécution suivante.', '50')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Ne traite qu\'un seul lot au lieu de vider la file (débogage).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Two simultaneous runs would process the same messages: idempotency would catch them, but
        // at the cost of doubled work and unreadable logs. The lock lives in the command rather than
        // in the cron line, so that manual invocations are protected too.
        if (!$this->lock()) {
            $io->comment('Une autre exécution est déjà en cours.');

            return Command::SUCCESS;
        }

        if ('' === $this->inboundQueueUrl) {
            $io->warning('AWS_MAIL_INBOUND_QUEUE_URL n\'est pas configurée : rien à consommer.');

            return Command::SUCCESS;
        }

        $deadline = time() + (int) $input->getOption('time-limit');
        $once = (bool) $input->getOption('once');
        $handled = 0;
        $failed = 0;

        while (true) {
            $messages = $this->receive();

            // Empty queue: the work is done, we hand back rather than wait for nothing.
            if ([] === $messages) {
                break;
            }

            foreach ($messages as $message) {
                if ($this->handle($message, $io)) {
                    ++$handled;
                } else {
                    ++$failed;
                }
            }

            // The unit of work is cleared between batches: a surge must not inflate the process's
            // memory message after message.
            $this->entityManager->clear();

            if ($once || time() >= $deadline) {
                break;
            }
        }

        if ($handled > 0 || $failed > 0) {
            $io->success(sprintf('%d message(s) traité(s), %d en échec (laissé(s) en file).', $handled, $failed));
        }

        return Command::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function receive(): array
    {
        $result = $this->mailSqsClient->receiveMessage([
            'QueueUrl' => $this->inboundQueueUrl,
            'MaxNumberOfMessages' => self::BATCH_SIZE,
            'WaitTimeSeconds' => self::WAIT_TIME_SECONDS,
        ]);

        return $result['Messages'] ?? [];
    }

    /** @param array<string, mixed> $message */
    private function handle(array $message, SymfonyStyle $io): bool
    {
        $receiptHandle = (string) $message['ReceiptHandle'];

        try {
            $keys = $this->extractKeys((string) $message['Body']);

            // Test notification emitted by S3 when the event is created, or a payload with no
            // usable record: nothing to process, but nothing to replay either.
            if ([] === $keys) {
                $this->delete($receiptHandle);

                return true;
            }

            foreach ($keys as $key) {
                $this->processor->process($key);
                $io->writeln(sprintf('  <info>✓</info> %s', $key), SymfonyStyle::VERBOSITY_VERBOSE);
            }

            $this->delete($receiptHandle);

            return true;
        } catch (\Throwable $exception) {
            // Deliberately no deletion: the message becomes visible again and SQS counts the
            // attempt. Five failures and it moves to the DLQ, where the CloudWatch alarm reports it.
            $this->logger->error('School mail: failed to process an inbound message.', [
                'exception' => $exception,
                'messageId' => $message['MessageId'] ?? null,
            ]);
            $io->writeln(sprintf('  <error>✗</error> %s', $exception->getMessage()), SymfonyStyle::VERBOSITY_VERBOSE);

            return false;
        }
    }

    /**
     * Extracts the S3 keys out of an event notification.
     *
     * Keys are URL-encoded in there: spaces become `+` and special characters are percent-encoded.
     * Forgetting that would produce `NoSuchKey` on any object with a non-trivial name - the keys
     * SES generates are plain, but decoding stays indispensable for DMARC reports and any manual
     * drop.
     *
     * @return list<string>
     */
    private function extractKeys(string $body): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        $keys = [];

        foreach ($payload['Records'] ?? [] as $record) {
            $key = $record['s3']['object']['key'] ?? null;

            if (\is_string($key) && '' !== $key) {
                $keys[] = urldecode(str_replace('+', ' ', $key));
            }
        }

        return $keys;
    }

    private function delete(string $receiptHandle): void
    {
        $this->mailSqsClient->deleteMessage([
            'QueueUrl' => $this->inboundQueueUrl,
            'ReceiptHandle' => $receiptHandle,
        ]);
    }
}
