<?php

declare(strict_types=1);

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

    /**
     * The library file this row was linked from, when it was one
     * (design/validated/file-library.md, "The link, on nine existing tables").
     *
     * **The row keeps its own storage key**, copied from the node. That is the decision that makes
     * the whole feature cheap: every reader - a Twig template, file_url(), the mobile API, the PDF
     * exports, the mail attachment builder - is untouched, because nothing about *reading* a file
     * changes. This foreign key exists for one purpose: to answer "where is this file used", with a
     * real index and a real constraint rather than a polymorphic (target_type, target_id) table
     * Doctrine could not key - and which nothing would clean up when a host disappears.
     *
     * `SET NULL` and never `CASCADE`: removing the links is App\Service\FileLibraryLinks's job, done
     * deliberately when the teacher confirms « Supprimer partout ». A cascade here would delete the
     * *host* row - the quiz question, the séquence resource, the video and its statistics - as a
     * database side effect nobody can see.
     */
    #[ORM\ManyToOne(targetEntity: FileLibraryNode::class)]
    #[ORM\JoinColumn(name: 'library_node_id', nullable: true, onDelete: 'SET NULL')]
    private ?FileLibraryNode $libraryNode = null;

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

    public function getLibraryNode(): ?FileLibraryNode
    {
        return $this->libraryNode;
    }

    public function setLibraryNode(?FileLibraryNode $libraryNode): self
    {
        $this->libraryNode = $libraryNode;

        return $this;
    }
}
