<?php

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
 * Vide la file SQS « inbound » : chaque message porte la clé S3 d'un `.eml` déposé par SES.
 *
 * Conçue pour être appelée périodiquement (cron, toutes les minutes) et non pour tourner en
 * permanence. Le choix est délibéré : le flux réel se compte en dizaines de mails par jour, pas
 * par seconde, et une minute de latence sur l'arrivée d'une candidature est invisible. En
 * contrepartie on n'a aucun processus résident à surveiller, aucune fuite mémoire à borner et
 * aucune politique de redémarrage à régler - la commande naît, vide la file, et meurt.
 *
 * Pourquoi ne pas passer par Symfony Messenger : SQS implémente déjà la sémantique dont on a
 * besoin - le visibility timeout *est* le délai avant nouvelle tentative, le receive count *est*
 * le compteur de tentatives, la DLQ *est* le transport d'échec, et une alarme CloudWatch surveille
 * sa profondeur. Superposer le mécanisme de reprise de Messenger aurait dérouté les échecs vers le
 * transport `failed` en base, laissant la DLQ vide et l'alarme muette.
 *
 * D'où la règle unique de cette boucle : **on ne supprime le message qu'après écriture réussie**.
 * En cas d'échec on ne fait rien, le message redevient visible au bout du visibility timeout, et
 * SQS le bascule en DLQ à la cinquième tentative. Une interruption brutale est donc sans
 * conséquence, et l'idempotence de App\Service\InboundMailProcessor empêche le doublon lors de la
 * relivraison.
 */
#[AsCommand(
    name: 'app:mail:consume-inbound',
    description: 'Vide la file SQS des mails entrants du Courrier école (à appeler par cron).',
)]
class ConsumeInboundMailCommand extends Command
{
    use LockableTrait;

    /** Le maximum autorisé par l'API SQS. */
    private const int BATCH_SIZE = 10;

    /**
     * Toute valeur strictement positive active le long polling, qui interroge l'ensemble des
     * serveurs de la file : une seconde suffit donc à garantir qu'un message présent est vu. On
     * reste bas parce qu'en exécution périodique, chaque seconde d'attente est une seconde de
     * processus PHP résident payée pour rien quand la file est vide.
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

        // Deux exécutions simultanées traiteraient les mêmes messages : l'idempotence les
        // rattraperait, mais au prix d'un travail doublé et de journaux illisibles. Le verrou vit
        // dans la commande et non dans la ligne de cron, pour protéger aussi les appels manuels.
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

            // File vide : le travail est fini, on rend la main plutôt que d'attendre pour rien.
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

            // L'unit of work est vidée entre les lots : un afflux ne doit pas faire enfler la
            // mémoire du processus au fil des messages.
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
