<?php

namespace App\Entity;

use App\Repository\SequenceInstanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen, one-way copy of a SequenceTemplate, instantiated against a specific Program - see
 * design/validated/teaching-sequence-library.md. Built by App\Service\SequenceInstantiationService,
 * never edited to match the template afterward: editing the source template later never changes
 * this row, and editing this row never writes back to the template.
 */
#[ORM\Entity(repositoryClass: SequenceInstanceRepository::class)]
#[ORM\Table(name: 'sequence_instance')]
class SequenceInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private ?Program $program = null;

    // Provenance only, not a live link - nullable with SET NULL so deleting the source template
    // later (e.g. the teacher cleaning up their library) never breaks an already-taught Program's
    // records.
    #[ORM\ManyToOne(targetEntity: SequenceTemplate::class)]
    #[ORM\JoinColumn(name: 'source_template_id', nullable: true, onDelete: 'SET NULL')]
    private ?SequenceTemplate $sourceTemplate = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    // Everything below is copied from the source template at instantiation time - frozen from
    // that point on.
    #[ORM\Column(length: 255)]
    private ?string $titre = null;

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

    /** @var Collection<int, SeanceInstance> */
    #[ORM\OneToMany(mappedBy: 'sequenceInstance', targetEntity: SeanceInstance::class)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $seanceInstances;

    public function __construct(Program $program, User $createdBy)
    {
        $this->program = $program;
        $this->createdBy = $createdBy;
        $this->creationDate = new \DateTimeImmutable();
        $this->seanceInstances = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getSourceTemplate(): ?SequenceTemplate
    {
        return $this->sourceTemplate;
    }

    public function setSourceTemplate(?SequenceTemplate $sourceTemplate): static
    {
        $this->sourceTemplate = $sourceTemplate;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
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

    /** @return Collection<int, SeanceInstance> */
    public function getSeanceInstances(): Collection
    {
        return $this->seanceInstances;
    }
}
