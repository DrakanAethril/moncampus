<?php

namespace App\Entity;

use App\Repository\ProgramRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Cohort's offering for a given SchoolYear (e.g. SIO1 for 2025-2026), the entity Options
 * and Modalities are actually attached to.
 */
#[ORM\Entity(repositoryClass: ProgramRepository::class)]
#[ORM\Table(name: 'program')]
class Program
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(name: 'short_name', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $shortName;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    // Nullable in PHP (unlike the DB column) purely so the form data mapper can pass through a
    // transiently-null value while re-applying the "cohort"/"schoolYear" fields' submitted data
    // after empty_data runs, without a TypeError - #[Assert\NotNull] still rejects it before
    // persist().
    #[ORM\ManyToOne(targetEntity: Cohort::class, inversedBy: 'programs')]
    #[ORM\JoinColumn(name: 'cohort_id', nullable: false)]
    #[Assert\NotNull]
    private ?Cohort $cohort = null;

    #[ORM\ManyToOne(targetEntity: SchoolYear::class, inversedBy: 'programs')]
    #[ORM\JoinColumn(name: 'school_year_id', nullable: false)]
    #[Assert\NotNull]
    private ?SchoolYear $schoolYear = null;

    /** @var Collection<int, Option> */
    #[ORM\ManyToMany(targetEntity: Option::class, mappedBy: 'programs')]
    private Collection $options;

    /** @var Collection<int, Modality> */
    #[ORM\ManyToMany(targetEntity: Modality::class, mappedBy: 'programs')]
    private Collection $modalities;

    // Program owns both of these (no inverse side on User - it doesn't need to know which
    // programs it's a member of for now).
    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'program_student')]
    private Collection $students;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'program_teacher')]
    private Collection $teachers;

    /** @var Collection<int, LessonSession> */
    #[ORM\OneToMany(mappedBy: 'program', targetEntity: LessonSession::class)]
    private Collection $lessonSessions;

    /** @var Collection<int, Topic> */
    #[ORM\OneToMany(mappedBy: 'program', targetEntity: Topic::class)]
    private Collection $topics;

    /** @var Collection<int, Skill> */
    #[ORM\OneToMany(mappedBy: 'program', targetEntity: Skill::class)]
    private Collection $skills;

    /** @var Collection<int, ProgramFinancialItem> */
    #[ORM\OneToMany(mappedBy: 'program', targetEntity: ProgramFinancialItem::class)]
    private Collection $financialItems;

    /** @var Collection<int, ProgramReport> */
    #[ORM\OneToMany(mappedBy: 'program', targetEntity: ProgramReport::class)]
    private Collection $reports;

    // Gate the nav/settings-tab entries for their respective feature areas - on by default so a
    // freshly created Program starts with everything available (see ProgramType's checkbox
    // fields and ProgramFeatureGuardTrait's use in the controllers that serve these areas).
    #[ORM\Column(name: 'timetable_management_enabled', options: ['default' => true])]
    private bool $timetableManagementEnabled = true;

    #[ORM\Column(name: 'financial_management_enabled', options: ['default' => true])]
    private bool $financialManagementEnabled = true;

    #[ORM\Column(name: 'topic_skill_management_enabled', options: ['default' => true])]
    private bool $topicSkillManagementEnabled = true;

    #[ORM\Column(name: 'internship_management_enabled', options: ['default' => true])]
    private bool $internshipManagementEnabled = true;

    public function __construct(string $name, string $shortName, Cohort $cohort, SchoolYear $schoolYear)
    {
        $this->name = $name;
        $this->shortName = $shortName;
        $this->creationDate = new \DateTimeImmutable();
        $this->options = new ArrayCollection();
        $this->modalities = new ArrayCollection();
        $this->students = new ArrayCollection();
        $this->teachers = new ArrayCollection();
        $this->lessonSessions = new ArrayCollection();
        $this->topics = new ArrayCollection();
        $this->skills = new ArrayCollection();
        $this->financialItems = new ArrayCollection();
        $this->reports = new ArrayCollection();
        $this->setCohort($cohort);
        $this->setSchoolYear($schoolYear);
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function setShortName(string $shortName): static
    {
        $this->shortName = $shortName;

        return $this;
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

    public function getCohort(): ?Cohort
    {
        return $this->cohort;
    }

    public function setCohort(?Cohort $cohort): static
    {
        $this->cohort = $cohort;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a
        // fresh query, not automatically from setting the owning side.
        if (null !== $cohort && !$cohort->getPrograms()->contains($this)) {
            $cohort->getPrograms()->add($this);
        }

        return $this;
    }

    public function getSchoolYear(): ?SchoolYear
    {
        return $this->schoolYear;
    }

    public function setSchoolYear(?SchoolYear $schoolYear): static
    {
        $this->schoolYear = $schoolYear;

        if (null !== $schoolYear && !$schoolYear->getPrograms()->contains($this)) {
            $schoolYear->getPrograms()->add($this);
        }

        return $this;
    }

    /** @return Collection<int, Option> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    // Option owns this ManyToMany (mappedBy 'programs' above), so Doctrine only persists
    // changes made through Option::addProgram()/removeProgram() - delegating to it here is
    // what lets the "options" field on Program's own form actually save. Symfony's form
    // adder/remover convention (by_reference: false) calls these for each added/removed choice.
    public function addOption(Option $option): static
    {
        if (!$this->options->contains($option)) {
            $option->addProgram($this);
        }

        return $this;
    }

    public function removeOption(Option $option): static
    {
        if ($this->options->contains($option)) {
            $option->removeProgram($this);
        }

        return $this;
    }

    /** @return Collection<int, Modality> */
    public function getModalities(): Collection
    {
        return $this->modalities;
    }

    // Same reasoning as addOption()/removeOption() above: Modality owns this ManyToMany.
    public function addModality(Modality $modality): static
    {
        if (!$this->modalities->contains($modality)) {
            $modality->addProgram($this);
        }

        return $this;
    }

    public function removeModality(Modality $modality): static
    {
        if ($this->modalities->contains($modality)) {
            $modality->removeProgram($this);
        }

        return $this;
    }

    /** @return Collection<int, User> */
    public function getStudents(): Collection
    {
        return $this->students;
    }

    public function addStudent(User $student): static
    {
        if (!$this->students->contains($student)) {
            $this->students->add($student);
        }

        return $this;
    }

    public function removeStudent(User $student): static
    {
        $this->students->removeElement($student);

        return $this;
    }

    /** @return Collection<int, User> */
    public function getTeachers(): Collection
    {
        return $this->teachers;
    }

    public function addTeacher(User $teacher): static
    {
        if (!$this->teachers->contains($teacher)) {
            $this->teachers->add($teacher);
        }

        return $this;
    }

    public function removeTeacher(User $teacher): static
    {
        $this->teachers->removeElement($teacher);

        return $this;
    }

    /** @return Collection<int, LessonSession> */
    public function getLessonSessions(): Collection
    {
        return $this->lessonSessions;
    }

    /** @return Collection<int, Topic> */
    public function getTopics(): Collection
    {
        return $this->topics;
    }

    /** @return Collection<int, Skill> */
    public function getSkills(): Collection
    {
        return $this->skills;
    }

    /** @return Collection<int, ProgramFinancialItem> */
    public function getFinancialItems(): Collection
    {
        return $this->financialItems;
    }

    /** @return Collection<int, ProgramReport> */
    public function getReports(): Collection
    {
        return $this->reports;
    }

    public function isTimetableManagementEnabled(): bool
    {
        return $this->timetableManagementEnabled;
    }

    public function setTimetableManagementEnabled(bool $timetableManagementEnabled): static
    {
        $this->timetableManagementEnabled = $timetableManagementEnabled;

        return $this;
    }

    public function isFinancialManagementEnabled(): bool
    {
        return $this->financialManagementEnabled;
    }

    public function setFinancialManagementEnabled(bool $financialManagementEnabled): static
    {
        $this->financialManagementEnabled = $financialManagementEnabled;

        return $this;
    }

    public function isTopicSkillManagementEnabled(): bool
    {
        return $this->topicSkillManagementEnabled;
    }

    public function setTopicSkillManagementEnabled(bool $topicSkillManagementEnabled): static
    {
        $this->topicSkillManagementEnabled = $topicSkillManagementEnabled;

        return $this;
    }

    public function isInternshipManagementEnabled(): bool
    {
        return $this->internshipManagementEnabled;
    }

    public function setInternshipManagementEnabled(bool $internshipManagementEnabled): static
    {
        $this->internshipManagementEnabled = $internshipManagementEnabled;

        return $this;
    }
}
