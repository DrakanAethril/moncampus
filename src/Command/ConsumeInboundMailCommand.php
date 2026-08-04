<?php

namespace App\Command;

use App\Service\InboundMailProcessor;
use Aws\Sqs\SqsClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Consomme la file SQS « inbound » : chaque message porte la clé S3 d'un `.eml` déposé par SES.
 *
 * Pourquoi une commande autonome plutôt qu'un transport Symfony Messenger : SQS implémente déjà
 * exactement la sémantique dont on a besoin - le visibility timeout *est* le délai avant nouvelle
 * tentative, le receive count *est* le compteur de tentatives, la DLQ *est* le transport d'échec,
 * et une alarme CloudWatch surveille sa profondeur. Superposer le mécanisme de reprise de
 * Messenger aurait dérouté les échecs vers le transport `failed` en base, laissant la DLQ
 * désespérément vide et l'alarme muette.
 *
 * D'où la règle unique de cette boucle : **on ne supprime le message qu'après écriture réussie**.
 * En cas d'échec on ne fait rien, le message redevient visible au bout du visibility timeout, et
 * SQS le bascule en DLQ à la cinquième tentative.
 *
 * Arrêt : l'image ne contient pas ext-pcntl, donc pas de gestion de signal (inutile d'implémenter
 * SignalableCommandInterface, il ne serait jamais appelé). La commande sort d'elle-même au bout de
 * `--time-limit` et Docker la relance - ce qui borne aussi la fuite mémoire d'un processus PHP
 * long. Une interruption brutale en plein traitement est sans conséquence : le message n'ayant pas
 * été supprimé, il sera relivré, et l'idempotence de App\Service\InboundMailProcessor empêche le
 * doublon.
 */
#[AsCommand(
    name: 'app:mail:consume-inbound',
    description: 'Consomme la file SQS des mails entrants du Courrier école.',
)]
class ConsumeInboundMailCommand extends Command
{
    /** Le maximum autorisé par l'API SQS. */
    private const int BATCH_SIZE = 10;

    /** Long polling : la valeur configurée sur la file, pour ne pas facturer des appels à vide. */
    private const int WAIT_TIME_SECONDS = 20;

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
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Durée de vie du processus, en secondes.', '900')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Ne traite qu\'un seul lot, puis sort (débogage).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $timeLimit = (int) $input->getOption('time-limit');

        if ('' === $this->inboundQueueUrl) {
            $io->warning('AWS_MAIL_INBOUND_QUEUE_URL n\'est pas configurée : worker en sommeil.');

            // Une pause suivie d'une sortie propre, plutôt qu'un échec. Le service est déclaré
            // dans compose, donc il démarre dès le déploiement - éventuellement avant que les
            // identifiants AWS n'aient été posés sur le serveur. Sortir en erreur ferait boucler
            // Docker sur un redémarrage par seconde et noierait les journaux ; ainsi le worker
            // reste dormant et silencieux jusqu'à ce qu'on le configure.
            sleep(min(300, max(1, $timeLimit)));

            return Command::SUCCESS;
        }

        $once = (bool) $input->getOption('once');
        $handled = 0;
        $failed = 0;

        $deadline = time() + $timeLimit;
        $io->text(sprintf('Écoute de %s', $this->inboundQueueUrl));

        do {
            $messages = $this->receive();

            foreach ($messages as $message) {
                if ($this->handle($message, $io)) {
                    ++$handled;
                } else {
                    ++$failed;
                }
            }

            // L'unit of work est vidée entre les lots : un processus qui tourne un quart d'heure
            // ne doit pas accumuler toutes les entités qu'il a touchées.
            $this->entityManager->clear();
        } while (!$once && time() < $deadline);

        $io->success(sprintf('%d message(s) traité(s), %d en échec (laissé(s) en file).', $handled, $failed));

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

            // Notification de test émise par S3 à la création de l'événement, ou payload sans
            // enregistrement exploitable : rien à traiter, mais rien à rejouer non plus.
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
            // Volontairement pas de suppression : le message redevient visible et SQS compte la
            // tentative. Cinq échecs et il part en DLQ, où l'alarme CloudWatch le signalera.
            $this->logger->error('Courrier école : échec de traitement d\'un message entrant.', [
                'exception' => $exception,
                'messageId' => $message['MessageId'] ?? null,
            ]);
            $io->writeln(sprintf('  <error>✗</error> %s', $exception->getMessage()), SymfonyStyle::VERBOSITY_VERBOSE);

            return false;
        }
    }

    /**
     * Extrait les clés S3 d'une notification d'événement.
     *
     * Les clés y sont encodées comme dans une URL : les espaces deviennent `+` et les caractères
     * spéciaux sont percent-encodés. Les oublier donnerait des `NoSuchKey` sur tout objet au nom
     * non trivial - les clés générées par SES sont sobres, mais le décodage reste indispensable
     * pour les rapports DMARC et tout dépôt manuel.
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
