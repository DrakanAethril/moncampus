<?php

namespace App\Entity;

use App\Repository\SeanceTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** One séance within a SequenceTemplate - see design/validated/teaching-sequence-library.md. */
#[ORM\Entity(repositoryClass: SeanceTemplateRepository::class)]
#[ORM\Table(name: 'seance_template')]
class SeanceTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SequenceTemplate::class, inversedBy: 'seanceTemplates')]
    #[ORM\JoinColumn(name: 'sequence_template_id', nullable: false)]
    private ?SequenceTemplate $sequenceTemplate = null;

    // Manually entered display order within the séquence - no drag-and-drop reordering, matches
    // the "don't add abstractions beyond what's needed" approach already used elsewhere in this
    // codebase for small ordered lists.
    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $titre = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $duree = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objectifs = null;

    #[ORM\Column(name: 'avant_description', type: Types::TEXT, nullable: true)]
    private ?string $avantDescription = null;

    #[ORM\Column(name: 'apres_description', type: Types::TEXT, nullable: true)]
    private ?string $apresDescription = null;

    // Facultatif - a library-level planning aid only (see class-level design doc): flags which
    // séances are core vs. nice-to-have when deciding what to instantiate for a given year's
    // actual hour allocation. Doesn't affect instantiation behavior itself.
    #[ORM\Column(name: 'is_optional')]
    private bool $isOptional = false;

    #[ORM\Column(name: 'optional_note', type: Types::TEXT, nullable: true)]
    private ?string $optionalNote = null;

    /** @var Collection<int, SeancePhaseTemplate> */
    #[ORM\OneToMany(mappedBy: 'seanceTemplate', targetEntity: SeancePhaseTemplate::class, orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $seancePhaseTemplates;

    /** @var Collection<int, LibraryResource> */
    #[ORM\OneToMany(mappedBy: 'seanceTemplate', targetEntity: LibraryResource::class, orphanRemoval: true)]
    private Collection $libraryResources;

    public function __construct(SequenceTemplate $sequenceTemplate)
    {
        $this->sequenceTemplate = $sequenceTemplate;
        $this->seancePhaseTemplates = new ArrayCollection();
        $this->libraryResources = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSequenceTemplate(): ?SequenceTemplate
    {
        return $this->sequenceTemplate;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getDuree(): ?string
    {
        return $this->duree;
    }

    public function setDuree(?string $duree): static
    {
        $this->duree = $duree;

        return $this;
    }

    public function getObjectifs(): ?string
    {
        return $this->objectifs;
    }

    public function setObjectifs(?string $objectifs): static
    {
        $this->objectifs = $objectifs;

        return $this;
    }

    public function getAvantDescription(): ?string
    {
        return $this->avantDescription;
    }

    public function setAvantDescription(?string $avantDescription): static
    {
        $this->avantDescription = $avantDescription;

        return $this;
    }

    public function getApresDescription(): ?string
    {
        return $this->apresDescription;
    }

    public function setApresDescription(?string $apresDescription): static
    {
        $this->apresDescription = $apresDescription;

        return $this;
    }

    public function isOptional(): bool
    {
        return $this->isOptional;
    }

    public function setIsOptional(bool $isOptional): static
    {
        $this->isOptional = $isOptional;

        return $this;
    }

    public function getOptionalNote(): ?string
    {
        return $this->optionalNote;
    }

    public function setOptionalNote(?string $optionalNote): static
    {
        $this->optionalNote = $optionalNote;

        return $this;
    }

    /** @return Collection<int, SeancePhaseTemplate> */
    public function getSeancePhaseTemplates(): Collection
    {
        return $this->seancePhaseTemplates;
    }

    /** @return Collection<int, LibraryResource> */
    public function getLibraryResources(): Collection
    {
        return $this->libraryResources;
    }
}
