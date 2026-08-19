<?php

declare(strict_types=1);

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

    /**
     * Co-animation: the OTHER teachers who deliver this matière to this class, and who may
     * therefore edit the plan - see design/validated/co-animation.md.
     *
     * The whole schema change of that feature is this join table, and it is deliberately here
     * rather than on the Topic: who *teaches* a matière is already derivable from the timetable
     * (TopicRepository::findTaughtByTeacherInProgram() reads it straight from the créneaux), so
     * storing it again would be a second truth to keep correct. What the timetable cannot derive
     * is who may modify the plan, and that is the one new fact.
     *
     * $teacher above stays the owner and stays the authority for the séquence pool and the créneau
     * pool - a co-teacher must see exactly the same lists as the owner, which is what the existing
     * "the progression's teacher, not whoever is looking" rule already gives for free.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'progression_co_teacher')]
    #[ORM\JoinColumn(name: 'progression_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'teacher_id', onDelete: 'CASCADE')]
    private Collection $coTeachers;

    public function __construct(Topic $topic, User $teacher)
    {
        $this->topic = $topic;
        $this->teacher = $teacher;
        $this->creationDate = new \DateTimeImmutable();
        $this->sequences = new ArrayCollection();
        $this->coTeachers = new ArrayCollection();
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

    /** @return Collection<int, User> */
    public function getCoTeachers(): Collection
    {
        return $this->coTeachers;
    }

    public function addCoTeacher(User $teacher): static
    {
        // The owner is never also a co-teacher: they already hold every right the link grants, and
        // a row naming them would print the same person twice on the cover of the export.
        if ($teacher !== $this->teacher && !$this->coTeachers->contains($teacher)) {
            $this->coTeachers->add($teacher);
        }

        return $this;
    }

    public function removeCoTeacher(User $teacher): static
    {
        $this->coTeachers->removeElement($teacher);

        return $this;
    }

    public function isCoTeacher(User $teacher): bool
    {
        return $this->coTeachers->contains($teacher);
    }

    public function isCoAnimated(): bool
    {
        return !$this->coTeachers->isEmpty();
    }

    /**
     * Everybody entitled to edit this plan, owner first - the order the export's « Formateurs »
     * rows and the 2a block both print.
     *
     * @return list<User>
     */
    public function getTeachers(): array
    {
        $teachers = null === $this->teacher ? [] : [$this->teacher];

        foreach ($this->coTeachers as $coTeacher) {
            $teachers[] = $coTeacher;
        }

        return $teachers;
    }

    // "Cybersécurité × SIO-2" - the breadcrumb's last segment on screens 5a/2a.
    public function getDisplayName(): string
    {
        return sprintf('%s × %s', $this->topic?->getName() ?? '—', $this->getProgram()?->getDisplayShortName() ?? '—');
    }

    // Minutes, like every duration in this module - see ProgressionSeance::$plannedMinutes.
    public function getPlacedMinutes(): int
    {
        $total = 0;
        foreach ($this->sequences as $sequence) {
            $total += $sequence->getPlacedMinutes();
        }

        return $total;
    }

    public function getPlannedMinutes(): int
    {
        $total = 0;
        foreach ($this->sequences as $sequence) {
            $total += $sequence->getPlannedMinutes();
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

        // Plus the ones the séquences carry themselves: a séance flagged "contient une évaluation"
        // is an evaluation the year holds, whether or not anyone has posed a Carnet de notes row
        // for it. A retirée séance stops counting, same as everywhere else.
        foreach ($this->sequences as $sequence) {
            foreach ($sequence->getActiveSeances() as $seance) {
                $nature = $seance->getEvaluationNature();
                if (null !== $nature) {
                    ++$counts[$nature->value];
                }
            }
        }

        return $counts;
    }
}
