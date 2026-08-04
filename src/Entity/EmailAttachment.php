<?php

namespace App\Entity;

use App\Enum\EmailScanVerdict;
use App\Repository\EmailAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;

/**
 * Une pièce jointe extraite d'un message et stockée sur S3 sous
 * `applications/{login}/attachments/{hash}/{nom-fichier}`.
 *
 * Le stockage est adressé par empreinte SHA-256 : deux élèves qui reçoivent la même plaquette
 * d'entreprise ne l'écrivent qu'une fois sur S3, tout en gardant chacun leur ligne ici (le nom
 * de fichier et le rattachement au message leur sont propres). L'index sur `content_hash` est ce
 * qui rend cette déduplication interrogeable.
 */
#[ORM\Entity(repositoryClass: EmailAttachmentRepository::class)]
#[ORM\Table(name: 'email_attachment')]
#[Index(name: 'idx_email_attachment_hash', columns: ['content_hash'])]
#[Index(name: 'idx_email_attachment_message', columns: ['email_message_id'])]
class EmailAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EmailMessage::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'email_message_id', nullable: false, onDelete: 'CASCADE')]
    private ?EmailMessage $emailMessage = null;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(name: 's3_key', length: 512)]
    private string $s3Key;

    /** SHA-256 en hexadécimal : 64 caractères, longueur fixe. */
    #[ORM\Column(name: 'content_hash', length: 64)]
    private string $contentHash;

    #[ORM\Column(name: 'size_bytes', type: Types::INTEGER)]
    private int $sizeBytes = 0;

    #[ORM\Column(name: 'content_type', length: 255, nullable: true)]
    private ?string $contentType = null;

    /**
     * Verdict antivirus repris de l'analyse SES du message porteur. ClamAV n'est pas déployé pour
     * l'instant (décision assumée) : cette colonne accueillera son verdict propre le jour où il
     * le sera, sans migration supplémentaire.
     */
    #[ORM\Column(name: 'scan_verdict', length: 20, nullable: true, enumType: EmailScanVerdict::class)]
    private ?EmailScanVerdict $scanVerdict = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmailMessage(): ?EmailMessage
    {
        return $this->emailMessage;
    }

    public function setEmailMessage(?EmailMessage $emailMessage): static
    {
        $this->emailMessage = $emailMessage;

        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

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

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $contentHash): static
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(int $sizeBytes): static
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(?string $contentType): static
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getScanVerdict(): ?EmailScanVerdict
    {
        return $this->scanVerdict;
    }

    public function setScanVerdict(?EmailScanVerdict $scanVerdict): static
    {
        $this->scanVerdict = $scanVerdict;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
