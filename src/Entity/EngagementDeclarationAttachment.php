<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EngagementDeclarationAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A piece of evidence attached to a declared engagement - an attestation, a link's export, a report.
 *
 * The same shape as every other attachment in this application: a storage key and a name, the file
 * itself having reached the bucket through App\Form\FilePickerType before the form was ever
 * submitted. **No form on this platform carries bytes**, and this one is no exception.
 *
 * The design points these at App\Entity\FileLibraryNode. That is not what happens here, and the
 * reason is in the code rather than in the design: the file library is a teachers' tool - a student
 * has no library to pick from - so a declaration takes an ordinary staged upload, exactly as a
 * training application does.
 */
#[ORM\Entity(repositoryClass: EngagementDeclarationAttachmentRepository::class)]
#[ORM\Table(name: 'engagement_declaration_attachment')]
class EngagementDeclarationAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EngagementDeclaration::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'declaration_id', nullable: false, onDelete: 'CASCADE')]
    private EngagementDeclaration $declaration;

    #[ORM\Column(name: 'storage_key', length: 500)]
    private string $storageKey;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(EngagementDeclaration $declaration, string $storageKey, string $name, int $position = 0)
    {
        $this->declaration = $declaration;
        $this->storageKey = $storageKey;
        $this->name = $name;
        $this->position = $position;
        $declaration->addAttachment($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeclaration(): EngagementDeclaration
    {
        return $this->declaration;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
