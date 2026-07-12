<?php

namespace App\Entity;

use App\Repository\SeanceInstanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen copy of a SeanceTemplate. $sequenceInstance is optional - a séance can be instantiated
 * standalone, detached from any séquence (the "gap-filling" case in
 * design/validated/teaching-sequence-library.md). $lessonSession is set once the teacher assigns
 * it a real date via App\Controller\ProgramSequenceInstanceController::schedule() - before that,
 * this row exists but backs no actual timetable slot yet.
 */
#[ORM\Entity(repositoryClass: SeanceInstanceRepository::class)]
#[ORM\Table(name: 'seance_instance')]
class SeanceInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: SequenceInstance::class, inversedBy: 'seanceInstances')]
    #[ORM\JoinColumn(name: 'sequence_instance_id', nullable: true)]
    private ?SequenceInstance $sequenceInstance = null;

    #[ORM\ManyToOne(targetEntity: SeanceTemplate::class)]
    #[ORM\JoinColumn(name: 'source_template_id', nullable: true, onDelete: 'SET NULL')]
    private ?SeanceTemplate $sourceTemplate = null;

    // Unidirectional owning OneToOne - LessonSession has no idea a SeanceInstance may back it,
    // same "don't add an inverse side we don't need" reasoning as LessonSession::$topic.
    #[ORM\OneToOne(targetEntity: LessonSession::class)]
    #[ORM\JoinColumn(name: 'lesson_session_id', nullable: true, unique: true)]
    private ?LessonSession $lessonSession = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column]
    private int $ordre = 0;

    // Copied from the source SeanceTemplate at instantiation time, then independently editable.
    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $duree = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objectifs = null;

    #[ORM\Column(name: 'avant_description', type: Types::TEXT, nullable: true)]
    private ?string $avantDescription = null;

    #[ORM\Column(name: 'apres_description', type: Types::TEXT, nullable: true)]
    private ?string $apresDescription = null;

    /** @var Collection<int, SeancePhaseInstance> */
    #[ORM\OneToMany(mappedBy: 'seanceInstance', targetEntity: SeancePhaseInstance::class, orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $seancePhaseInstances;

    public function __construct(Program $program, User $createdBy)
    {
        $this->program = $program;
        $this->createdBy = $createdBy;
        $this->creationDate = new \DateTimeImmutable();
        $this->seancePhaseInstances = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
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

    public function getSourceTemplate(): ?SeanceTemplate
    {
        return $this->sourceTemplate;
    }

    public function setSourceTemplate(?SeanceTemplate $sourceTemplate): static
    {
        $this->sourceTemplate = $sourceTemplate;

        return $this;
    }

    public function getLessonSession(): ?LessonSession
    {
        return $this->lessonSession;
    }

    public function setLessonSession(?LessonSession $lessonSession): static
    {
        $this->lessonSession = $lessonSession;

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

    /** @return Collection<int, SeancePhaseInstance> */
    public function getSeancePhaseInstances(): Collection
    {
        return $this->seancePhaseInstances;
    }
}
