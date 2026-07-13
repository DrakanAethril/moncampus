<?php

namespace App\Entity;

use App\Repository\MessageAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One uploaded file attached to a Message - see App\Service\FileUploadService. */
#[ORM\Entity(repositoryClass: MessageAttachmentRepository::class)]
#[ORM\Table(name: 'message_attachment')]
class MessageAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'message_id', nullable: false)]
    private ?Message $message = null;

    // S3 object key - not a URL, see App\Service\FileUploadService.
    #[ORM\Column(name: 'storage_key', length: 255)]
    private ?string $storageKey = null;

    // The filename as it was uploaded - $storageKey is randomized to avoid collisions, so this is
    // what's actually shown/used as the download name.
    #[ORM\Column(name: 'original_filename', length: 255)]
    private ?string $originalFilename = null;

    #[ORM\Column(name: 'uploaded_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $uploadedAt = null;

    public function __construct(Message $message, string $storageKey, string $originalFilename)
    {
        $this->message = $message;
        $this->storageKey = $storageKey;
        $this->originalFilename = $originalFilename;
        $this->uploadedAt = new \DateTimeImmutable();

        if (!$message->getAttachments()->contains($this)) {
            $message->getAttachments()->add($this);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function getUploadedAt(): ?\DateTimeImmutable
    {
        return $this->uploadedAt;
    }
}
