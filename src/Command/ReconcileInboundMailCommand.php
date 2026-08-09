<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EmailMessageRepository;
use App\Service\InboundMailProcessor;
use Aws\S3\S3Client;
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
 * The last-resort safety net of the mail pipeline
 * (design_handoff_courrier_ecole_infra §7, "job de réconciliation").
 *
 * Lists what SES dropped under `incoming/` and replays whatever never made it into the database. It
 * exists because everything upstream can silently lose a message - an S3 notification that never
 * fired, a queue purged by hand, a DLQ nobody drained - and S3 is the source of truth, so anything
 * lost there is recoverable from here.
 *
 * Meant for a nightly cron. `--since` bounds the scan to recent objects, which is what a nightly run
 * wants; a full sweep stays possible by passing a wide window after an incident.
 */
#[AsCommand(
    name: 'app:mail:reconcile',
    description: 'Rejoue les mails déposés sur S3 qui ne sont pas en base (filet de sécurité, à appeler par cron).',
)]
class ReconcileInboundMailCommand extends Command
{
    use LockableTrait;

    /** Everything SES drops lands under this prefix - the rest of the bucket is our own filing. */
    private const string INCOMING_PREFIX = 'incoming/';

    public function __construct(
        private readonly S3Client $mailS3Client,
        private readonly InboundMailProcessor $processor,
        private readonly EmailMessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $mailBucket,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Ne regarde que les objets déposés depuis cette date (format relatif accepté).', '-7 days')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste ce qui serait rejoué, sans rien écrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->comment('Une autre exécution est déjà en cours.');

            return Command::SUCCESS;
        }

        if ('' === $this->mailBucket) {
            $io->warning('AWS_MAIL_BUCKET n\'est pas configuré : rien à réconcilier.');

            return Command::SUCCESS;
        }

        $since = new \DateTimeImmutable((string) $input->getOption('since'));
        $dryRun = (bool) $input->getOption('dry-run');
        $scanned = 0;
        $replayed = 0;
        $failed = 0;

        foreach ($this->listIncoming($since) as $key) {
            ++$scanned;

            // The same check the worker runs, and for the same reason: replaying a mail already
            // stored would duplicate nothing, but would cost a download and a parse for nothing.
            if (null !== $this->messageRepository->findOneBySourceKey($key)) {
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('  <comment>~</comment> %s', $key));
                ++$replayed;

                continue;
            }

            try {
                $this->processor->process($key);
                $io->writeln(sprintf('  <info>✓</info> %s', $key));
                ++$replayed;
            } catch (\Throwable $exception) {
                ++$failed;
                $this->logger->error('School mail: reconciliation could not replay an object.', [
                    'key' => $key,
                    'exception' => $exception,
                ]);
                $io->writeln(sprintf('  <error>✗</error> %s — %s', $key, $exception->getMessage()));
            }

            $this->entityManager->clear();
        }

        $io->success(sprintf('%d objet(s) examiné(s), %d rejoué(s), %d en échec.', $scanned, $replayed, $failed));

        // A reconciliation that had work to do is a signal in itself: the normal path lost
        // something, and that deserves to be visible outside this console.
        if ($replayed > 0 && !$dryRun) {
            $this->logger->warning('School mail: reconciliation had to replay objects the queue never delivered.', [
                'replayed' => $replayed,
            ]);
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return iterable<string> */
    private function listIncoming(\DateTimeImmutable $since): iterable
    {
        $paginator = $this->mailS3Client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->mailBucket,
            'Prefix' => self::INCOMING_PREFIX,
        ]);

        /** @var array{Contents?: list<array{Key: string, LastModified?: ?\DateTimeInterface}>} $page */
        foreach ($paginator as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $lastModified = $object['LastModified'] ?? null;

                if (null !== $lastModified && $lastModified->getTimestamp() < $since->getTimestamp()) {
                    continue;
                }

                yield $object['Key'];
            }
        }
    }
}
