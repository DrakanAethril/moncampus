<?php

namespace App\Entity;

use App\Repository\SequenceTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A teacher's personal, reusable "séquence" template - see
 * design/validated/teaching-sequence-library.md. Owned by a teacher, not a Program: no dates, no
 * Program attachment. Instantiating it (SequenceInstance) makes a frozen, one-way copy - editing
 * this template afterward never touches any instance already created from it, and vice versa.
 * Hard-deleted like LessonSession - a teacher's own draft content, no audit trail needed beyond
 * the $teacher ownership itself.
 */
#[ORM\Entity(repositoryClass: SequenceTemplateRepository::class)]
#[ORM\Table(name: 'sequence_template')]
class SequenceTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    private ?User $teacher = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $titre = null;

    // Manually entered display order in the library list - same reasoning as
    // SeanceTemplate::$ordre (no drag-and-drop reordering, matches this codebase's "don't add
    // abstractions beyond what's needed" approach for small ordered lists) - named in English
    // (unlike that sibling field) since this one is new, per this codebase's own code-in-English
    // convention; "order" is a reserved SQL word, hence the quoted column name.
    #[ORM\Column(name: '`order`')]
    private int $order = 0;

    // Niveau/Option/Blocs are free-text tags private to this teacher (App\Entity\AbstractLibraryTag)
    // - deliberately NOT related to the real Cohort/Option/Bloc entities, so a teacher can tag
    // content against a Niveau/Option/Bloc that doesn't officially exist. All optional: a teacher
    // may not know or care to tag every facet.
    #[ORM\ManyToOne(targetEntity: LibraryNiveauTag::class)]
    #[ORM\JoinColumn(name: 'niveau_tag_id', nullable: true)]
    private ?LibraryNiveauTag $niveau = null;

    #[ORM\ManyToOne(targetEntity: LibraryOptionTag::class)]
    #[ORM\JoinColumn(name: 'option_tag_id', nullable: true)]
    private ?LibraryOptionTag $option = null;

    /** @var Collection<int, LibraryBlocTag> */
    #[ORM\ManyToMany(targetEntity: LibraryBlocTag::class)]
    #[ORM\JoinTable(name: 'sequence_template_bloc_tag')]
    private Collection $blocs;

    #[ORM\Column(name: 'capacites_attendues', type: Types::TEXT, nullable: true)]
    private ?string $capacitesAttendues = null;

    #[ORM\Column(name: 'pre_requis', type: Types::TEXT, nullable: true)]
    private ?string $preRequis = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objectifs = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $transversalites = null;

    #[ORM\Column(name: 'situation_problematique', type: Types::TEXT, nullable: true)]
    private ?string $situationProblematique = null;

    #[ORM\Column(name: 'supports_generaux', type: Types::TEXT, nullable: true)]
    private ?string $supportsGeneraux = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    /** @var Collection<int, SeanceTemplate> */
    #[ORM\OneToMany(mappedBy: 'sequenceTemplate', targetEntity: SeanceTemplate::class, orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $seanceTemplates;

    /** @var Collection<int, LibraryResource> */
    #[ORM\OneToMany(mappedBy: 'sequenceTemplate', targetEntity: LibraryResource::class, orphanRemoval: true)]
    private Collection $libraryResources;

    public function __construct(User $teacher)
    {
        $this->teacher = $teacher;
        $this->blocs = new ArrayCollection();
        $this->seanceTemplates = new ArrayCollection();
        $this->libraryResources = new ArrayCollection();
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

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function setOrder(int $order): static
    {
        $this->order = $order;

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

    public function getCapacitesAttendues(): ?string
    {
        return $this->capacitesAttendues;
    }

    public function setCapacitesAttendues(?string $capacitesAttendues): static
    {
        $this->capacitesAttendues = $capacitesAttendues;

        return $this;
    }

    public function getPreRequis(): ?string
    {
        return $this->preRequis;
    }

    public function setPreRequis(?string $preRequis): static
    {
        $this->preRequis = $preRequis;

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

    public function getTransversalites(): ?string
    {
        return $this->transversalites;
    }

    public function setTransversalites(?string $transversalites): static
    {
        $this->transversalites = $transversalites;

        return $this;
    }

    public function getSituationProblematique(): ?string
    {
        return $this->situationProblematique;
    }

    public function setSituationProblematique(?string $situationProblematique): static
    {
        $this->situationProblematique = $situationProblematique;

        return $this;
    }

    public function getSupportsGeneraux(): ?string
    {
        return $this->supportsGeneraux;
    }

    public function setSupportsGeneraux(?string $supportsGeneraux): static
    {
        $this->supportsGeneraux = $supportsGeneraux;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    /** @return Collection<int, SeanceTemplate> */
    public function getSeanceTemplates(): Collection
    {
        return $this->seanceTemplates;
    }

    /** @return Collection<int, LibraryResource> */
    public function getLibraryResources(): Collection
    {
        return $this->libraryResources;
    }
}
