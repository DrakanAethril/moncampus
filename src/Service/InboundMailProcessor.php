<?php

namespace App\Service;

use App\Entity\EmailMessage;
use App\Enum\EmailDirection;
use App\Enum\EmailScanVerdict;
use App\Repository\EmailAliasRepository;
use App\Repository\EmailMessageRepository;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Traite un `.eml` déposé par SES sous `incoming/` : parsing MIME, résolution de l'élève
 * destinataire, rangement sous `applications/{login}/mails/`, écriture en base.
 *
 * Deux invariants gouvernent ce service :
 *
 * 1. **S3 fait foi.** L'objet d'origine sous `incoming/` n'est jamais supprimé ici - c'est la
 *    règle de cycle de vie du bucket (30 jours) qui s'en charge, bien après que le rangement a
 *    réussi. Un bug de ce service ne peut donc pas détruire un mail.
 * 2. **Idempotent.** SQS livre au moins une fois. Le contrôle porte sur la clé S3 d'origine puis
 *    sur le Message-ID, et il a lieu *avant* tout travail coûteux.
 *
 * Hors périmètre de cette première tranche, volontairement : l'extraction des pièces jointes et
 * le rattachement aux candidatures, qui dépendent du handoff de la partie 2 encore en design. Un
 * message dont l'élève n'est pas résolu est conservé avec `student` à NULL - c'est la file
 * « à rattacher », rien n'est perdu.
 */
class InboundMailProcessor
{
    public function __construct(
        private readonly S3Client $mailS3Client,
        private readonly EmailAliasRepository $aliasRepository,
        private readonly EmailMessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $mailBucket,
        private readonly string $studentMailDomain,
    ) {
    }

    /**
     * @return bool true si le message a été traité ou l'était déjà (le message SQS peut être
     *              supprimé) ; toute erreur remonte en exception pour que le message reste en file
     */
    public function process(string $sourceKey): bool
    {
        if (null !== $this->messageRepository->findOneBySourceKey($sourceKey)) {
            $this->logger->info('Courrier école : objet déjà traité, ignoré.', ['key' => $sourceKey]);

            return true;
        }

        $raw = $this->download($sourceKey);
        $parsed = (new MailMimeParser())->parse($raw, true);

        $messageId = $this->normalizeMessageId($parsed->getHeaderValue('Message-ID'));

        if (null !== $messageId && null !== $this->messageRepository->findOneByMessageId($messageId)) {
            $this->logger->info('Courrier école : Message-ID déjà en base, ignoré.', [
                'key' => $sourceKey,
                'messageId' => $messageId,
            ]);

            return true;
        }

        $message = $this->buildMessage($parsed, $sourceKey, $messageId);

        // Le rangement précède l'écriture en base : si la copie S3 échoue, rien n'est enregistré
        // et le message repassera par la file. L'inverse laisserait une ligne pointant vers une
        // clé inexistante.
        $message->setS3Key($this->file($sourceKey, $message));

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->logger->info('Courrier école : message entrant enregistré.', [
            'key' => $sourceKey,
            'messageId' => $messageId,
            'student' => $message->getStudent()?->getUsername(),
        ]);

        return true;
    }

    private function download(string $key): string
    {
        $result = $this->mailS3Client->getObject([
            'Bucket' => $this->mailBucket,
            'Key' => $key,
        ]);

        return (string) $result['Body'];
    }

    private function buildMessage(IMessage $parsed, string $sourceKey, ?string $messageId): EmailMessage
    {
        $from = $this->firstAddress($parsed, 'From');
        $toAddresses = $this->addresses($parsed, 'To');
        $ccAddresses = $this->addresses($parsed, 'Cc');

        $message = (new EmailMessage())
            ->setDirection(EmailDirection::Inbound)
            ->setMessageId($messageId)
            ->setSourceKey($sourceKey)
            ->setFromAddress($from['email'] ?? 'unknown@invalid')
            ->setFromName($from['name'] ?? null)
            ->setToAddresses($toAddresses)
            ->setCcAddresses($ccAddresses)
            ->setSubject($parsed->getSubject())
            ->setTextBody($parsed->getTextContent())
            ->setHtmlBody($parsed->getHtmlContent())
            ->setInReplyTo($this->normalizeMessageId($parsed->getHeaderValue('In-Reply-To')))
            ->setReferencesHeader($parsed->getHeaderValue('References'))
            ->setSpamVerdict(EmailScanVerdict::fromHeader($parsed->getHeaderValue('X-SES-Spam-Verdict')))
            ->setVirusVerdict(EmailScanVerdict::fromHeader($parsed->getHeaderValue('X-SES-Virus-Verdict')))
            ->setMessageDate($this->messageDate($parsed));

        $localPart = $this->resolveRecipientLocalPart(array_merge($toAddresses, $ccAddresses));
        $message->setRecipientLocalPart($localPart);

        if (null !== $localPart) {
            $message->setStudent($this->aliasRepository->findOneByLocalPart($localPart)?->getUser());
        }

        if (null === $message->getStudent()) {
            $this->logger->notice('Courrier école : destinataire non résolu, mis en attente de rattachement.', [
                'key' => $sourceKey,
                'localPart' => $localPart,
            ]);
        }

        return $message;
    }

    /**
     * Copie le brut sous `applications/{login}/mails/`, ou sous `unattributed/` quand l'élève n'a
     * pas pu être résolu. La copie est côté S3 (CopyObject), le contenu ne repasse pas par PHP.
     */
    private function file(string $sourceKey, EmailMessage $message): string
    {
        $owner = $message->getStudent()?->getUsername();
        $name = $this->objectName($message->getMessageId(), $sourceKey);

        $destination = null !== $owner
            ? sprintf('applications/%s/mails/%s.eml', $owner, $name)
            : sprintf('unattributed/mails/%s.eml', $name);

        $this->mailS3Client->copyObject([
            'Bucket' => $this->mailBucket,
            'Key' => $destination,
            'CopySource' => rawurlencode($this->mailBucket.'/'.$sourceKey),
        ]);

        return $destination;
    }

    /**
     * Un Message-ID contient des caractères parfaitement légaux en mail mais pénibles en clé S3
     * (`/`, espaces, `%`). On le réduit donc à une empreinte, en gardant la clé d'origine comme
     * repli quand l'en-tête manque.
     */
    private function objectName(?string $messageId, string $sourceKey): string
    {
        $seed = $messageId ?? $sourceKey;

        return preg_replace('/[^A-Za-z0-9._-]/', '_', trim($seed, '<>')) ?? hash('sha256', $seed);
    }

    /** La première adresse du domaine étudiant trouvée parmi les destinataires. */
    private function resolveRecipientLocalPart(array $addresses): ?string
    {
        $suffix = '@'.mb_strtolower($this->studentMailDomain);

        foreach ($addresses as $address) {
            $lowered = mb_strtolower($address);

            if (str_ends_with($lowered, $suffix)) {
                return substr($lowered, 0, -\strlen($suffix));
            }
        }

        return null;
    }

    /** @return list<string> */
    private function addresses(IMessage $parsed, string $header): array
    {
        $found = $parsed->getHeader($header);

        if (!$found instanceof AddressHeader) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($address): string => $address->getEmail(),
            $found->getAddresses(),
        )));
    }

    /** @return array{email?: string, name?: string} */
    private function firstAddress(IMessage $parsed, string $header): array
    {
        $found = $parsed->getHeader($header);

        if (!$found instanceof AddressHeader) {
            return [];
        }

        $first = $found->getAddresses()[0] ?? null;

        if (null === $first) {
            return [];
        }

        $name = $first->getName();

        return ['email' => $first->getEmail(), 'name' => '' !== $name ? $name : null];
    }

    private function messageDate(IMessage $parsed): ?\DateTimeImmutable
    {
        $header = $parsed->getHeader('Date');

        return $header instanceof DateHeader ? $header->getDateTimeImmutable() : null;
    }

    /** Les crochets sont conservés (c'est la forme canonique), le reste est normalisé. */
    private function normalizeMessageId(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        if ('' === $trimmed) {
            return null;
        }

        // Un References/In-Reply-To peut en contenir plusieurs : seul le premier nous intéresse.
        if (preg_match('/<[^>]+>/', $trimmed, $matches)) {
            $trimmed = $matches[0];
        }

        return mb_substr($trimmed, 0, 255);
    }
}
