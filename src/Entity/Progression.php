<?php

namespace App\Entity;

use App\Enum\EvaluationNature;
use App\Repository\ProgressionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One teacher's yearly plan for one class × one subject - see
 * design/design_handoff_progression/README.md.
 *
 * Anchored on a single Topic rather than a (class, subject, year, teacher) quadruple, because a
 * Topic already IS that quadruple: it belongs to exactly one Program (= Cohort × SchoolYear),
 * carries the subject name and its target hour volumes, and names the teacher who owns it (see
 * Topic's and Evaluation's own docblocks - "a Topic *is* one teacher's gradebook"). The OneToOne
 * therefore enforces the design's uniqueness rule for free, and the créneaux this progression can
 * place séances on are simply the LessonSessions carrying that same topic_id.
 */
#[ORM\Entity(repositoryClass: ProgressionRepository::class)]
#[ORM\Table(name: 'progression')]
class Progression
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Topic $topic = null;

    // Denormalized from $topic->getTeacher() at creation time - a Topic's teacher can be
    // reassigned by staff later, and a progression must stay owned by whoever actually built it
    // (it's their planning, not the class's).
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    private ?User $teacher = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    /** @var Collection<int, ProgressionSequence> */
    #[ORM\OneToMany(mappedBy: 'progression', targetEntity: ProgressionSequence::class, orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $sequences;

    public function __construct(Topic $topic, User $teacher)
    {
        $this->topic = $topic;
        $this->teacher = $teacher;
        $this->creationDate = new \DateTimeImmutable();
        $this->sequences = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTopic(): ?Topic
    {
        return $this->topic;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    public function getProgram(): ?Program
    {
        return $this->topic?->getProgram();
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    /** @return Collection<int, ProgressionSequence> */
    public function getSequences(): Collection
    {
        return $this->sequences;
    }

    public function addSequence(ProgressionSequence $sequence): static
    {
        if (!$this->sequences->contains($sequence)) {
            $this->sequences->add($sequence);
        }

        return $this;
    }

    public function removeSequence(ProgressionSequence $sequence): static
    {
        $this->sequences->removeElement($sequence);

        return $this;
    }

    // "Cybersécurité × SIO-2" - the breadcrumb's last segment on screens 5a/2a.
    public function getDisplayName(): string
    {
        return sprintf('%s × %s', $this->topic?->getName() ?? '—', $this->getProgram()?->getDisplayShortName() ?? '—');
    }

    public function getPlacedHours(): float
    {
        $total = 0.0;
        foreach ($this->sequences as $sequence) {
            $total += $sequence->getPlacedHours();
        }

        return $total;
    }

    public function getPlannedHours(): float
    {
        $total = 0.0;
        foreach ($this->sequences as $sequence) {
            $total += $sequence->getPlannedHours();
        }

        return $total;
    }

    /**
     * "Évaluations posées : x D · y F · z S" (rail of 5a, column of 3a). Counted straight off the
     * Carnet de notes rows of this progression's Topic - posing an evaluation from the progression
     * creates an App\Entity\Evaluation with a $nature, so there is exactly one source of truth.
     *
     * @return array<string, int> keyed by EvaluationNature::value
     */
    public function getEvaluationCountsByNature(): array
    {
        $counts = [
            EvaluationNature::Diagnostic->value => 0,
            EvaluationNature::Formative->value => 0,
            EvaluationNature::Summative->value => 0,
        ];

        foreach ($this->topic?->getEvaluations() ?? [] as $evaluation) {
            $nature = $evaluation->getNature();
            if (null !== $nature && null === $evaluation->getInactiveDate()) {
                ++$counts[$nature->value];
            }
        }

        return $counts;
    }
}
