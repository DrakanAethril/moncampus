<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\InboundMailProcessor;
use Aws\S3\S3Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DEVELOPMENT TOOL - drops a locally built `.eml` under `incoming/` and runs the inbound worker on
 * it, so the parsing, attachment extraction and reply-linking paths can be exercised without a real
 * mail travelling through SES and SQS.
 *
 * Not versioned, like the seed commands: it writes into the mail bucket and must never exist
 * anywhere but a developer's machine.
 */
#[AsCommand(name: 'app:dev:inject-inbound-mail', description: '[dev] Injecte un .eml local dans le pipeline entrant.')]
class DevInjectInboundMailCommand extends Command
{
    public function __construct(
        // The mail bucket's own client: plain autowiring would hand over the uploads one, whose
        // credentials have no business in this bucket.
        #[Autowire(service: 'mail.s3_client')]
        private readonly S3Client $mailS3Client,
        private readonly InboundMailProcessor $processor,
        #[Autowire('%env(AWS_MAIL_BUCKET)%')]
        private readonly string $mailBucket,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Chemin du .eml à injecter.')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'Clé S3 sous incoming/.', 'incoming/dev-inject.eml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('dev' !== $this->environment) {
            $io->error('Commande réservée à l\'environnement de développement.');

            return Command::FAILURE;
        }

        $file = (string) $input->getOption('file');
        $key = (string) $input->getOption('key');

        if (!is_readable($file)) {
            $io->error(sprintf('Fichier illisible : %s', $file));

            return Command::FAILURE;
        }

        $this->mailS3Client->putObject([
            'Bucket' => $this->mailBucket,
            'Key' => $key,
            'Body' => file_get_contents($file),
            'ContentType' => 'message/rfc822',
        ]);

        $this->processor->process($key);
        $io->success(sprintf('Injecté et traité : %s', $key));

        return Command::SUCCESS;
    }
}
