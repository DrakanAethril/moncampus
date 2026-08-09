<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrainingApplicationAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One file joined to a submission of a practice application
 * (design_handoff_postulation_redaction, screens 8b and 8f).
 *
 * A plain list, deliberately untyped: the student attaches whatever they judge useful, as many
 * files as they want, and nothing here says which one is "the CV". The validator still passes
 * judgement on a CV and a cover letter (screen 8d), but that is their reading of what was sent, not
 * a slot the compose screen forces the student into - "pas de slot typé CV / LM, pas de bouton
 * distinct par type de document".
 */
#[ORM\Entity(repositoryClass: TrainingApplicationAttachmentRepository::class)]
#[ORM\Table(name: 'training_application_attachment')]
#[ORM\Index(name: 'idx_training_attachment_version', columns: ['version_id'])]
class TrainingApplicationAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrainingApplicationVersion::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'version_id', nullable: false, onDelete: 'CASCADE')]
    private ?TrainingApplicationVersion $version = null;

    #[ORM\Column(name: 'storage_key', length: 512)]
    private string $storageKey;

    /** The name the file had on the student's machine - the only one that means anything to a reader. */
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    public function __construct(string $storageKey, string $name, int $position = 0)
    {
        $this->storageKey = $storageKey;
        $this->name = $name;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVersion(): ?TrainingApplicationVersion
    {
        return $this->version;
    }

    public function setVersion(?TrainingApplicationVersion $version): static
    {
        $this->version = $version;

        return $this;
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

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
