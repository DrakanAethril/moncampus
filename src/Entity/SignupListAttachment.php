<?php

namespace App\Entity;

use App\Repository\SignupListAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One uploaded file attached to a SignupList - see App\Service\FileUploadService. */
#[ORM\Entity(repositoryClass: SignupListAttachmentRepository::class)]
#[ORM\Table(name: 'signup_list_attachment')]
class SignupListAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SignupList::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'signup_list_id', nullable: false)]
    private ?SignupList $signupList = null;

    // S3 object key - not a URL, see App\Service\FileUploadService.
    #[ORM\Column(name: 'storage_key', length: 255)]
    private ?string $storageKey = null;

    // The filename as it was uploaded - $storageKey is randomized to avoid collisions, so this is
    // what's actually shown/used as the download name.
    #[ORM\Column(name: 'original_filename', length: 255)]
    private ?string $originalFilename = null;

    #[ORM\Column(name: 'uploaded_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $uploadedAt = null;

    public function __construct(SignupList $signupList, string $storageKey, string $originalFilename)
    {
        $this->signupList = $signupList;
        $this->storageKey = $storageKey;
        $this->originalFilename = $originalFilename;
        $this->uploadedAt = new \DateTimeImmutable();

        if (!$signupList->getAttachments()->contains($this)) {
            $signupList->getAttachments()->add($this);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSignupList(): ?SignupList
    {
        return $this->signupList;
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
