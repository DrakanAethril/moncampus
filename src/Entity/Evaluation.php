<?php

namespace App\Entity;

use App\Enum\EvaluationModality;
use App\Enum\EvaluationStatus;
use App\Enum\EvaluationType;
use App\Repository\EvaluationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One graded assessment within a Topic (Carnet de notes - design/design_handoff_projet/
 * PROMPT_CLAUDE_CODE_carnet_de_notes.md). Anchored to Topic rather than a dedicated
 * (Program, teacher, subject) composite - Topic::$teacher already uniquely identifies "this
 * teacher teaches this class x matière" (see App\Controller\ProgramTimetableSettingsController::
 * teamTab()), so a Topic *is* one teacher's gradebook.
 *
 * Deliberately carries no App\Entity\EvaluationPeriod link - which period an evaluation belongs to
 * is computed dynamically from $date against the Program's EvaluationPeriodGroup (if any), not
 * stored, so re-editing period boundaries later never needs to re-migrate evaluations (see
 * EvaluationPeriod::contains()).
 */
#[ORM\Entity(repositoryClass: EvaluationRepository::class)]
#[ORM\Table(name: 'evaluation')]
class Evaluation
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Topic::class, inversedBy: 'evaluations')]
    #[ORM\JoinColumn(name: 'topic_id', nullable: false)]
    #[Assert\NotNull]
    private ?Topic $topic = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(length: 20, enumType: EvaluationType::class)]
    private EvaluationType $type = EvaluationType::Written;

    #[ORM\Column(length: 20, enumType: EvaluationModality::class)]
    private EvaluationModality $modality = EvaluationModality::Individual;

    #[ORM\Column(length: 20, enumType: EvaluationStatus::class)]
    private EvaluationStatus $status = EvaluationStatus::Planned;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    // "Barème" in the design - the maximum a raw Grade::$value can reach for this evaluation
    // (e.g. 20, 10, 40...), independent of whether it then gets normalized to /20 for averaging
    // (see $countsOutOf20 and App\Service\EvaluationAverageCalculator::normalize()).
    #[ORM\Column]
    #[Assert\GreaterThanOrEqual(1)]
    private float $scale = 20.0;

    #[ORM\Column]
    #[Assert\GreaterThanOrEqual(0.5)]
    private float $coefficient = 1.0;

    // Design's "ramener sur 20" toggle - false keeps a raw Grade::$value as-is (out of $scale)
    // when averaging instead of rescaling it to /20 first.
    #[ORM\Column(name: 'counts_out_of_20')]
    private bool $countsOutOf20 = true;

    // Null = visible immediately. A future timestamp hides this evaluation (and its grades) from
    // students entirely - see App\Security\Voter\EvaluationVoter for the student-facing check.
    #[ORM\Column(name: 'visible_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $visibleAt = null;

    /** @var Collection<int, EvaluationRubricSection> */
    #[ORM\OneToMany(targetEntity: EvaluationRubricSection::class, mappedBy: 'evaluation', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $rubricSections;

    /** @var Collection<int, Grade> */
    #[ORM\OneToMany(targetEntity: Grade::class, mappedBy: 'evaluation', orphanRemoval: true)]
    private Collection $grades;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(Topic $topic, string $name, \DateTimeImmutable $date)
    {
        $this->creationDate = new \DateTimeImmutable();
        $this->rubricSections = new ArrayCollection();
        $this->grades = new ArrayCollection();
        $this->setTopic($topic);
        $this->name = $name;
        $this->date = $date;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTopic(): ?Topic
    {
        return $this->topic;
    }

    public function setTopic(?Topic $topic): static
    {
        $this->topic = $topic;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): EvaluationType
    {
        return $this->type;
    }

    public function setType(EvaluationType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getModality(): EvaluationModality
    {
        return $this->modality;
    }

    public function setModality(EvaluationModality $modality): static
    {
        $this->modality = $modality;

        return $this;
    }

    public function getStatus(): EvaluationStatus
    {
        return $this->status;
    }

    public function setStatus(EvaluationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getScale(): float
    {
        return $this->scale;
    }

    public function setScale(float $scale): static
    {
        $this->scale = $scale;

        return $this;
    }

    public function getCoefficient(): float
    {
        return $this->coefficient;
    }

    public function setCoefficient(float $coefficient): static
    {
        $this->coefficient = $coefficient;

        return $this;
    }

    public function countsOutOf20(): bool
    {
        return $this->countsOutOf20;
    }

    public function setCountsOutOf20(bool $countsOutOf20): static
    {
        $this->countsOutOf20 = $countsOutOf20;

        return $this;
    }

    public function getVisibleAt(): ?\DateTimeImmutable
    {
        return $this->visibleAt;
    }

    public function setVisibleAt(?\DateTimeImmutable $visibleAt): static
    {
        $this->visibleAt = $visibleAt;

        return $this;
    }

    public function isVisibleAt(\DateTimeImmutable $now): bool
    {
        return null === $this->visibleAt || $this->visibleAt <= $now;
    }

    /** @return Collection<int, EvaluationRubricSection> */
    public function getRubricSections(): Collection
    {
        return $this->rubricSections;
    }

    public function addRubricSection(EvaluationRubricSection $section): static
    {
        if (!$this->rubricSections->contains($section)) {
            $this->rubricSections->add($section);
            $section->setEvaluation($this);
        }

        return $this;
    }

    public function removeRubricSection(EvaluationRubricSection $section): static
    {
        $this->rubricSections->removeElement($section);

        return $this;
    }

    public function hasRubric(): bool
    {
        return !$this->rubricSections->isEmpty();
    }

    /** @return Collection<int, Grade> */
    public function getGrades(): Collection
    {
        return $this->grades;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }
}
