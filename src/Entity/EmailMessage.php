<?php

namespace App\Entity;

use App\Enum\EmailDeliveryStatus;
use App\Enum\EmailDirection;
use App\Enum\EmailScanVerdict;
use App\Repository\EmailMessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un mail de la boîte « Courrier école », reçu ou envoyé. Le `.eml` complet vit sur S3 et fait
 * foi ; cette ligne n'en est qu'une projection interrogeable.
 *
 * Idempotence - le point qui gouverne toute la conception : SQS livre *au moins* une fois, donc
 * le même message sera traité deux fois tôt ou tard. Deux clés uniques l'empêchent d'être écrit
 * deux fois :
 * - `message_id`, l'en-tête RFC, qui vaut aussi pour les envois (on le fixe nous-mêmes) ;
 * - `source_key`, la clé S3 du dépôt SES, qui prend le relais quand un message entrant malformé
 *   arrive sans Message-ID exploitable.
 * Les deux sont nullables : MySQL autorise plusieurs NULL dans un index unique, ce qui est
 * exactement le comportement voulu (un envoi n'a pas de `source_key`, une réception cassée n'a
 * pas de `message_id`).
 */
#[ORM\Entity(repositoryClass: EmailMessageRepository::class)]
#[ORM\Table(name: 'email_message')]
#[ORM\UniqueConstraint(name: 'uniq_email_message_message_id', columns: ['message_id'])]
#[ORM\UniqueConstraint(name: 'uniq_email_message_source_key', columns: ['source_key'])]
#[ORM\Index(name: 'idx_email_message_student', columns: ['student_id'])]
#[ORM\Index(name: 'idx_email_message_job_application', columns: ['job_application_id'])]
#[ORM\Index(name: 'idx_email_message_in_reply_to', columns: ['in_reply_to'])]
#[ORM\Index(name: 'idx_email_message_direction_date', columns: ['direction', 'message_date'])]
class EmailMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * L'en-tête Message-ID, crochets compris. 255 caractères couvre tout ce qui existe en
     * pratique ; au-delà on préfère tronquer et retomber sur `source_key` plutôt que d'imposer
     * un index sur colonne longue.
     */
    #[ORM\Column(name: 'message_id', length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(length: 20, enumType: EmailDirection::class)]
    private EmailDirection $direction;

    /**
     * L'élève propriétaire de la boîte. Nullable à dessein : un message entrant dont l'adresse
     * ne correspond à aucun alias connu (faute de frappe, spam, élève sorti) est conservé avec
     * `student` à NULL - c'est ça, la file « à rattacher », pas une table séparée.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $student = null;

    /** La partie locale visée, conservée même sans élève trouvé : c'est elle qu'on montre en revue manuelle. */
    #[ORM\Column(name: 'recipient_local_part', length: 64, nullable: true)]
    private ?string $recipientLocalPart = null;

    #[ORM\Column(name: 'from_address', length: 255)]
    private string $fromAddress;

    #[ORM\Column(name: 'from_name', length: 255, nullable: true)]
    private ?string $fromName = null;

    /** @var list<string> */
    #[ORM\Column(name: 'to_addresses', type: Types::JSON)]
    private array $toAddresses = [];

    /** @var list<string> */
    #[ORM\Column(name: 'cc_addresses', type: Types::JSON)]
    private array $ccAddresses = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(name: 'text_body', type: Types::TEXT, nullable: true)]
    private ?string $textBody = null;

    #[ORM\Column(name: 'html_body', type: Types::TEXT, nullable: true)]
    private ?string $htmlBody = null;

    /** Clé du `.eml` après rangement sous `applications/{login}/mails/`. */
    #[ORM\Column(name: 's3_key', length: 512)]
    private string $s3Key;

    /** Clé du dépôt SES d'origine sous `incoming/`, gardée comme second garde-fou d'idempotence. */
    #[ORM\Column(name: 'source_key', length: 255, nullable: true)]
    private ?string $sourceKey = null;

    /** En-tête In-Reply-To : la clé de rattachement d'une réponse à l'envoi qui l'a provoquée. */
    #[ORM\Column(name: 'in_reply_to', length: 255, nullable: true)]
    private ?string $inReplyTo = null;

    /**
     * En-tête References, la chaîne complète du fil. Colonne nommée `references_header` et non
     * `references` : REFERENCES est un mot réservé MySQL, et la table refuserait d'être créée.
     */
    #[ORM\Column(name: 'references_header', type: Types::TEXT, nullable: true)]
    private ?string $referencesHeader = null;

    #[ORM\Column(name: 'spam_verdict', length: 20, nullable: true, enumType: EmailScanVerdict::class)]
    private ?EmailScanVerdict $spamVerdict = null;

    #[ORM\Column(name: 'virus_verdict', length: 20, nullable: true, enumType: EmailScanVerdict::class)]
    private ?EmailScanVerdict $virusVerdict = null;

    /**
     * La démarche à laquelle ce mail se rattache. Nullable : un message entrant peut arriver avant
     * qu'on sache à quoi le rattacher, et c'est précisément la file de revue manuelle de l'écran 5a.
     *
     * Une réponse hérite de la démarche de l'envoi auquel elle répond (In-Reply-To → Message-ID),
     * sans qu'aucune question ne soit posée à l'élève - principe n°5 du handoff écrans.
     */
    #[ORM\ManyToOne(targetEntity: JobApplication::class, inversedBy: 'emailMessages')]
    #[ORM\JoinColumn(name: 'job_application_id', nullable: true, onDelete: 'SET NULL')]
    private ?JobApplication $jobApplication = null;

    /** Renseigné pour les envois seulement : un message reçu n'a pas de statut d'acheminement. */
    #[ORM\Column(name: 'delivery_status', length: 20, nullable: true, enumType: EmailDeliveryStatus::class)]
    private ?EmailDeliveryStatus $deliveryStatus = null;

    /** L'en-tête Date du message - ce que l'expéditeur affirme, à ne pas confondre avec createdAt. */
    #[ORM\Column(name: 'message_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $messageDate = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Le moment où l'élève a ouvert ce message dans sa boîte (écran 3b). Ne concerne que les
     * entrants, et ne dit rien de ce qui se passe chez le destinataire d'un envoi : le handoff
     * interdit toute détection d'ouverture côté entreprise (principe n°1).
     */
    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    /** @var Collection<int, EmailAttachment> */
    #[ORM\OneToMany(mappedBy: 'emailMessage', targetEntity: EmailAttachment::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $attachments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): static
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getDirection(): EmailDirection
    {
        return $this->direction;
    }

    public function setDirection(EmailDirection $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function setStudent(?User $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getRecipientLocalPart(): ?string
    {
        return $this->recipientLocalPart;
    }

    public function setRecipientLocalPart(?string $recipientLocalPart): static
    {
        $this->recipientLocalPart = $recipientLocalPart;

        return $this;
    }

    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    public function setFromAddress(string $fromAddress): static
    {
        $this->fromAddress = $fromAddress;

        return $this;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function setFromName(?string $fromName): static
    {
        $this->fromName = $fromName;

        return $this;
    }

    /** @return list<string> */
    public function getToAddresses(): array
    {
        return $this->toAddresses;
    }

    /** @param list<string> $toAddresses */
    public function setToAddresses(array $toAddresses): static
    {
        $this->toAddresses = $toAddresses;

        return $this;
    }

    /** @return list<string> */
    public function getCcAddresses(): array
    {
        return $this->ccAddresses;
    }

    /** @param list<string> $ccAddresses */
    public function setCcAddresses(array $ccAddresses): static
    {
        $this->ccAddresses = $ccAddresses;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getTextBody(): ?string
    {
        return $this->textBody;
    }

    public function setTextBody(?string $textBody): static
    {
        $this->textBody = $textBody;

        return $this;
    }

    public function getHtmlBody(): ?string
    {
        return $this->htmlBody;
    }

    public function setHtmlBody(?string $htmlBody): static
    {
        $this->htmlBody = $htmlBody;

        return $this;
    }

    public function getS3Key(): string
    {
        return $this->s3Key;
    }

    public function setS3Key(string $s3Key): static
    {
        $this->s3Key = $s3Key;

        return $this;
    }

    public function getSourceKey(): ?string
    {
        return $this->sourceKey;
    }

    public function setSourceKey(?string $sourceKey): static
    {
        $this->sourceKey = $sourceKey;

        return $this;
    }

    public function getInReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    public function setInReplyTo(?string $inReplyTo): static
    {
        $this->inReplyTo = $inReplyTo;

        return $this;
    }

    public function getReferencesHeader(): ?string
    {
        return $this->referencesHeader;
    }

    public function setReferencesHeader(?string $referencesHeader): static
    {
        $this->referencesHeader = $referencesHeader;

        return $this;
    }

    public function getSpamVerdict(): ?EmailScanVerdict
    {
        return $this->spamVerdict;
    }

    public function setSpamVerdict(?EmailScanVerdict $spamVerdict): static
    {
        $this->spamVerdict = $spamVerdict;

        return $this;
    }

    public function getVirusVerdict(): ?EmailScanVerdict
    {
        return $this->virusVerdict;
    }

    public function setVirusVerdict(?EmailScanVerdict $virusVerdict): static
    {
        $this->virusVerdict = $virusVerdict;

        return $this;
    }

    public function getDeliveryStatus(): ?EmailDeliveryStatus
    {
        return $this->deliveryStatus;
    }

    public function setDeliveryStatus(?EmailDeliveryStatus $deliveryStatus): static
    {
        $this->deliveryStatus = $deliveryStatus;

        return $this;
    }

    public function getMessageDate(): ?\DateTimeImmutable
    {
        return $this->messageDate;
    }

    public function setMessageDate(?\DateTimeImmutable $messageDate): static
    {
        $this->messageDate = $messageDate;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function setReadAt(?\DateTimeImmutable $readAt): static
    {
        $this->readAt = $readAt;

        return $this;
    }

    public function isUnread(): bool
    {
        return EmailDirection::Inbound === $this->direction && null === $this->readAt;
    }

    public function getJobApplication(): ?JobApplication
    {
        return $this->jobApplication;
    }

    public function setJobApplication(?JobApplication $jobApplication): static
    {
        $this->jobApplication = $jobApplication;

        return $this;
    }

    /** @return Collection<int, EmailAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(EmailAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setEmailMessage($this);
        }

        return $this;
    }

    /** Un message entrant sans propriétaire résolu : il attend une revue manuelle. */
    public function needsManualAttribution(): bool
    {
        return EmailDirection::Inbound === $this->direction && null === $this->student;
    }
}
