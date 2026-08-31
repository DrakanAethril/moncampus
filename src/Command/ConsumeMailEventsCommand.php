<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MailEventProcessor;
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
 * Drains the "events" SQS queue: SES delivery notifications about mails we sent
 * (design_handoff_courrier_ecole_infra §6).
 *
 * Same shape as App\Command\ConsumeInboundMailCommand, and for the same reasons - periodic rather
 * than resident, SQS's own semantics rather than Messenger's, deletion only after a successful
 * write. The two queues stay separate because they fail differently: losing an inbound mail loses a
 * student's correspondence, losing an event only leaves a status stale until the next one.
 *
 * One deliberate difference: an event about a mail we do not know yet is *not* deleted. The event
 * queue can outrun our own commit, and dropping it would leave that send stuck on "sent" forever.
 */
#[AsCommand(
    name: 'app:mail:consume-events',
    description: "Vide la file SQS des événements d'envoi SES du Courrier pro (à appeler par cron).",
)]
class ConsumeMailEventsCommand extends Command
{
    use LockableTrait;

    /** The maximum the SQS API allows. */
    private const int BATCH_SIZE = 10;

    private const int WAIT_TIME_SECONDS = 2;

    public function __construct(
        private readonly SqsClient $mailSqsClient,
        private readonly MailEventProcessor $processor,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $eventsQueueUrl,
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

        if (!$this->lock()) {
            $io->comment('Une autre exécution est déjà en cours.');

            return Command::SUCCESS;
        }

        if ('' === $this->eventsQueueUrl) {
            $io->warning('AWS_MAIL_EVENTS_QUEUE_URL n\'est pas configurée : rien à consommer.');

            return Command::SUCCESS;
        }

        $deadline = time() + (int) $input->getOption('time-limit');
        $once = (bool) $input->getOption('once');
        $handled = 0;
        $skipped = 0;

        while (true) {
            $messages = $this->receive();

            if ([] === $messages) {
                break;
            }

            foreach ($messages as $message) {
                if ($this->handle($message, $io)) {
                    ++$handled;
                } else {
                    ++$skipped;
                }
            }

            $this->entityManager->clear();

            if ($once || time() >= $deadline) {
                break;
            }
        }

        if ($handled > 0 || $skipped > 0) {
            $io->success(sprintf('%d événement(s) traité(s), %d laissé(s) en file.', $handled, $skipped));
        }

        return Command::SUCCESS;
    }

    /** @return list<array{MessageId?: string, ReceiptHandle: string, Body: string}> */
    private function receive(): array
    {
        $result = $this->mailSqsClient->receiveMessage([
            'QueueUrl' => $this->eventsQueueUrl,
            'MaxNumberOfMessages' => self::BATCH_SIZE,
            'WaitTimeSeconds' => self::WAIT_TIME_SECONDS,
        ]);

        return $result['Messages'] ?? [];
    }

    /** @param array{MessageId?: string, ReceiptHandle: string, Body: string} $message */
    private function handle(array $message, SymfonyStyle $io): bool
    {
        $receiptHandle = $message['ReceiptHandle'];

        try {
            if (!$this->processor->process($message['Body'])) {
                // The mail is unknown for now: leave the event visible so a later run catches up.
                return false;
            }

            $this->delete($receiptHandle);
            $io->writeln('  <info>✓</info> événement traité', SymfonyStyle::VERBOSITY_VERBOSE);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('School mail: failed to process an SES event.', [
                'exception' => $exception,
                'messageId' => $message['MessageId'] ?? null,
            ]);
            $io->writeln(sprintf('  <error>✗</error> %s', $exception->getMessage()), SymfonyStyle::VERBOSITY_VERBOSE);

            return false;
        }
    }

    private function delete(string $receiptHandle): void
    {
        $this->mailSqsClient->deleteMessage([
            'QueueUrl' => $this->eventsQueueUrl,
            'ReceiptHandle' => $receiptHandle,
        ]);
    }
}
