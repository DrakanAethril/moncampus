<?php

declare(strict_types=1);

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
 * One mail of the "School mail" box, received or sent. The full `.eml` lives on S3 and is
 * authoritative; this row is only a queryable projection of it.
 *
 * Idempotency - the point that governs the whole design: SQS delivers *at least* once, so the same
 * message will be processed twice sooner or later. Two unique keys keep it from being written
 * twice:
 * - `message_id`, the RFC header, which also covers sends (we set it ourselves);
 * - `source_key`, the S3 key of the SES drop, which takes over when a malformed inbound message
 *   arrives without a usable Message-ID.
 * Both are nullable: MySQL allows several NULLs inside a unique index, which is exactly the wanted
 * behaviour (a send has no `source_key`, a broken reception has no `message_id`).
 */
#[ORM\Entity(repositoryClass: EmailMessageRepository::class)]
#[ORM\Table(name: 'email_message')]
#[ORM\UniqueConstraint(name: 'uniq_email_message_message_id', columns: ['message_id'])]
#[ORM\UniqueConstraint(name: 'uniq_email_message_source_key', columns: ['source_key'])]
#[ORM\Index(name: 'idx_email_message_student', columns: ['student_id'])]
#[ORM\Index(name: 'idx_email_message_job_application', columns: ['job_application_id'])]
#[ORM\Index(name: 'idx_email_message_in_reply_to', columns: ['in_reply_to'])]
#[ORM\Index(name: 'idx_email_message_provider_message_id', columns: ['provider_message_id'])]
#[ORM\Index(name: 'idx_email_message_direction_date', columns: ['direction', 'message_date'])]
class EmailMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The Message-ID header, angle brackets included. 255 characters cover everything seen in
     * practice; beyond that we would rather truncate and fall back on `source_key` than impose an
     * index on a long column.
     */
    #[ORM\Column(name: 'message_id', length: 255, nullable: true)]
    private ?string $messageId = null;

    /**
     * The identifier SES gave the mail when it accepted it, without brackets or domain
     * (`0113019fd066c22f-...-000000`).
     *
     * Needed because **SES rewrites the Message-ID header**: whatever we set is replaced, the
     * recipient sees `<{this}@{region}.amazonses.com>`, and the delivery events published on the
     * "events" queue speak of nothing else. Correlating on our own identifier would therefore never
     * match a single event, nor a single reply.
     */
    #[ORM\Column(name: 'provider_message_id', length: 255, nullable: true)]
    private ?string $providerMessageId = null;

    #[ORM\Column(length: 20, enumType: EmailDirection::class)]
    private EmailDirection $direction;

    /**
     * The student who owns the mailbox. Nullable on purpose: an inbound message whose address
     * matches no known alias (typo, spam, student who left) is kept with `student` at NULL - that
     * is what the "to be linked" queue is, not a separate table.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $student = null;

    /** The local part aimed at, kept even with no student found: it is what manual review shows. */
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

    /** Key of the `.eml` once filed under `applications/{login}/mails/`. */
    #[ORM\Column(name: 's3_key', length: 512)]
    private string $s3Key;

    /** Key of the original SES drop under `incoming/`, kept as a second idempotency guard. */
    #[ORM\Column(name: 'source_key', length: 255, nullable: true)]
    private ?string $sourceKey = null;

    /** In-Reply-To header: how a reply is linked back to the mail that prompted it. */
    #[ORM\Column(name: 'in_reply_to', length: 255, nullable: true)]
    private ?string $inReplyTo = null;

    /**
     * References header, the thread's full chain. The column is named `references_header` and not
     * `references`: REFERENCES is a MySQL reserved word, and the table would refuse to be created.
     */
    #[ORM\Column(name: 'references_header', type: Types::TEXT, nullable: true)]
    private ?string $referencesHeader = null;

    #[ORM\Column(name: 'spam_verdict', length: 20, nullable: true, enumType: EmailScanVerdict::class)]
    private ?EmailScanVerdict $spamVerdict = null;

    #[ORM\Column(name: 'virus_verdict', length: 20, nullable: true, enumType: EmailScanVerdict::class)]
    private ?EmailScanVerdict $virusVerdict = null;

    /**
     * The application this mail belongs to. Nullable: an inbound message can arrive before we know
     * what to link it to, and that is precisely screen 5a's manual review queue.
     *
     * A reply inherits the application of the mail it answers (In-Reply-To -> Message-ID), without
     * a single question asked of the student - principle #5 of the screens handoff.
     */
    #[ORM\ManyToOne(targetEntity: JobApplication::class, inversedBy: 'emailMessages')]
    #[ORM\JoinColumn(name: 'job_application_id', nullable: true, onDelete: 'SET NULL')]
    private ?JobApplication $jobApplication = null;

    /** Set for sends only: a received message has no delivery status. */
    #[ORM\Column(name: 'delivery_status', length: 20, nullable: true, enumType: EmailDeliveryStatus::class)]
    private ?EmailDeliveryStatus $deliveryStatus = null;

    /** The message's Date header - what the sender claims, not to be confused with createdAt. */
    #[ORM\Column(name: 'message_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $messageDate = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * When the student opened this message in their mailbox (screen 3b). Inbound only, and it says
     * nothing about what happens at the recipient's end of a send: the handoff forbids any open
     * tracking on the company side (principle #1).
     */
    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    /**
     * When the student moved this mail to their Trash (screen 3b). A soft delete on purpose: the
     * `.eml` stays on S3, which is the source of truth, and the teacher screens (1a/2a) must keep
     * counting a mail the student tidied away - what left for a company left for good.
     */
    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

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

    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function setProviderMessageId(?string $providerMessageId): static
    {
        $this->providerMessageId = $providerMessageId;

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

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
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

    /** An inbound message with no resolved owner: it is waiting for manual review. */
    public function needsManualAttribution(): bool
    {
        return EmailDirection::Inbound === $this->direction && null === $this->student;
    }
}
