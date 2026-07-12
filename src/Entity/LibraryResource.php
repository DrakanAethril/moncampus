<?php

namespace App\Entity;

use App\Enum\LibraryResourceSourceType;
use App\Repository\LibraryResourceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file (upload) or a URL a teacher attaches to their library content - see
 * design/validated/teaching-sequence-library.md. Attached at exactly one of $sequenceTemplate/
 * $seanceTemplate/$seancePhaseTemplate (séquence-level, avant-séance, or per-phase moyens/
 * supports) - never more than one, enforced by the controller that constructs it rather than
 * here, same reasoning as LessonLogAttachment's upload-vs-link XOR. Tagged with the same free-text
 * Niveau/Option/Bloc facets as SequenceTemplate itself (App\Entity\AbstractLibraryTag), purely as
 * descriptive metadata for now - the design doc leaves whether/how this should power filtering/
 * search in the library UI as an open question, so no such UI is built yet.
 */
#[ORM\Entity(repositoryClass: LibraryResourceRepository::class)]
#[ORM\Table(name: 'library_resource')]
class LibraryResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    private ?User $teacher = null;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\Column(length: 20, enumType: LibraryResourceSourceType::class)]
    private ?LibraryResourceSourceType $type = null;

    // S3 object key when $type is Upload (see App\Service\FileUploadService) - not a URL, keeps
    // the bucket/CloudFront domain changeable without a data migration.
    #[ORM\Column(name: 'storage_key', length: 255, nullable: true)]
    private ?string $storageKey = null;

    // External URL when $type is Link.
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url = null;

    /** @var Collection<int, LibraryBlocTag> */
    #[ORM\ManyToMany(targetEntity: LibraryBlocTag::class)]
    #[ORM\JoinTable(name: 'library_resource_bloc_tag')]
    private Collection $blocs;

    #[ORM\ManyToOne(targetEntity: LibraryNiveauTag::class)]
    #[ORM\JoinColumn(name: 'niveau_tag_id', nullable: true)]
    private ?LibraryNiveauTag $niveau = null;

    #[ORM\ManyToOne(targetEntity: LibraryOptionTag::class)]
    #[ORM\JoinColumn(name: 'option_tag_id', nullable: true)]
    private ?LibraryOptionTag $option = null;

    #[ORM\ManyToOne(targetEntity: SequenceTemplate::class, inversedBy: 'libraryResources')]
    #[ORM\JoinColumn(name: 'sequence_template_id', nullable: true)]
    private ?SequenceTemplate $sequenceTemplate = null;

    #[ORM\ManyToOne(targetEntity: SeanceTemplate::class, inversedBy: 'libraryResources')]
    #[ORM\JoinColumn(name: 'seance_template_id', nullable: true)]
    private ?SeanceTemplate $seanceTemplate = null;

    #[ORM\ManyToOne(targetEntity: SeancePhaseTemplate::class, inversedBy: 'libraryResources')]
    #[ORM\JoinColumn(name: 'seance_phase_template_id', nullable: true)]
    private ?SeancePhaseTemplate $seancePhaseTemplate = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct(User $teacher, string $label)
    {
        $this->teacher = $teacher;
        $this->label = $label;
        $this->blocs = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
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

    /** @return Collection<int, LibraryBlocTag> */
    public function getBlocs(): Collection
    {
        return $this->blocs;
    }

    public function addBloc(LibraryBlocTag $bloc): static
    {
        if (!$this->blocs->contains($bloc)) {
            $this->blocs->add($bloc);
        }

        return $this;
    }

    public function removeBloc(LibraryBlocTag $bloc): static
    {
        $this->blocs->removeElement($bloc);

        return $this;
    }

    public function getNiveau(): ?LibraryNiveauTag
    {
        return $this->niveau;
    }

    public function setNiveau(?LibraryNiveauTag $niveau): static
    {
        $this->niveau = $niveau;

        return $this;
    }

    public function getOption(): ?LibraryOptionTag
    {
        return $this->option;
    }

    public function setOption(?LibraryOptionTag $option): static
    {
        $this->option = $option;

        return $this;
    }

    public function getSequenceTemplate(): ?SequenceTemplate
    {
        return $this->sequenceTemplate;
    }

    public function setSequenceTemplate(?SequenceTemplate $sequenceTemplate): static
    {
        $this->sequenceTemplate = $sequenceTemplate;

        return $this;
    }

    public function getSeanceTemplate(): ?SeanceTemplate
    {
        return $this->seanceTemplate;
    }

    public function setSeanceTemplate(?SeanceTemplate $seanceTemplate): static
    {
        $this->seanceTemplate = $seanceTemplate;

        return $this;
    }

    public function getSeancePhaseTemplate(): ?SeancePhaseTemplate
    {
        return $this->seancePhaseTemplate;
    }

    public function setSeancePhaseTemplate(?SeancePhaseTemplate $seancePhaseTemplate): static
    {
        $this->seancePhaseTemplate = $seancePhaseTemplate;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }
}
