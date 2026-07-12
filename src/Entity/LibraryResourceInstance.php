<?php

namespace App\Entity;

use App\Enum\LibraryResourceSourceType;
use App\Repository\LibraryResourceInstanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen, duplicated copy of a LibraryResource, attached at exactly one of $sequenceInstance/
 * $seanceInstance/$seancePhaseInstance - see design/validated/teaching-sequence-library.md's
 * "Attached documents are duplicated, not referenced, at instantiation time". For an Upload-type
 * resource, $storageKey points at a real second S3 object (copied by
 * App\Service\SequenceInstantiationService via App\Service\FileUploadService::copy()), not the
 * original library file - deleting or replacing the library original can never change this row.
 * No facet tags (Bloc/Niveau/Option) here - those exist on LibraryResource purely to help a
 * teacher browse their own library, meaningless once attached to real, already-scheduled content.
 */
#[ORM\Entity(repositoryClass: LibraryResourceInstanceRepository::class)]
#[ORM\Table(name: 'library_resource_instance')]
class LibraryResourceInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\Column(length: 20, enumType: LibraryResourceSourceType::class)]
    private ?LibraryResourceSourceType $type = null;

    #[ORM\Column(name: 'storage_key', length: 255, nullable: true)]
    private ?string $storageKey = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\ManyToOne(targetEntity: SequenceInstance::class, inversedBy: 'libraryResourceInstances')]
    #[ORM\JoinColumn(name: 'sequence_instance_id', nullable: true)]
    private ?SequenceInstance $sequenceInstance = null;

    #[ORM\ManyToOne(targetEntity: SeanceInstance::class, inversedBy: 'libraryResourceInstances')]
    #[ORM\JoinColumn(name: 'seance_instance_id', nullable: true)]
    private ?SeanceInstance $seanceInstance = null;

    #[ORM\ManyToOne(targetEntity: SeancePhaseInstance::class, inversedBy: 'libraryResourceInstances')]
    #[ORM\JoinColumn(name: 'seance_phase_instance_id', nullable: true)]
    private ?SeancePhaseInstance $seancePhaseInstance = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct(string $label)
    {
        $this->label = $label;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getType(): ?LibraryResourceSourceType
    {
        return $this->type;
    }

    public function setType(?LibraryResourceSourceType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function setStorageKey(?string $storageKey): static
    {
        $this->storageKey = $storageKey;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getSequenceInstance(): ?SequenceInstance
    {
        return $this->sequenceInstance;
    }

    public function setSequenceInstance(?SequenceInstance $sequenceInstance): static
    {
        $this->sequenceInstance = $sequenceInstance;

        return $this;
    }

    public function getSeanceInstance(): ?SeanceInstance
    {
        return $this->seanceInstance;
    }

    public function setSeanceInstance(?SeanceInstance $seanceInstance): static
    {
        $this->seanceInstance = $seanceInstance;

        return $this;
    }

    public function getSeancePhaseInstance(): ?SeancePhaseInstance
    {
        return $this->seancePhaseInstance;
    }

    public function setSeancePhaseInstance(?SeancePhaseInstance $seancePhaseInstance): static
    {
        $this->seancePhaseInstance = $seancePhaseInstance;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }
}
