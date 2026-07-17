<?php

namespace App\Entity;

use App\Repository\ProgramRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    // Optional override of the SchoolYear's own start/end - a Program's actual training period
    // (e.g. an apprenticeship contract) rarely lines up exactly with the school year's bounds.
    // Null means "use the SchoolYear's date" - see getEffectiveStartDate()/getEffectiveEndDate(),
    // the only accessors the timetable calendar (lesson_timetable_controller.js's validRange)
    // and agenda should ever read.
    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    // Optional - not every Program necessarily needs a calendar structure, and existing Programs
    // have none yet (retrofitted field).
    #[ORM\ManyToOne(targetEntity: PeriodGroup::class, inversedBy: 'programs')]
    #[ORM\JoinColumn(name: 'period_group_id', nullable: true)]
    private ?PeriodGroup $periodGroup = null;

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

    // A tag on a subset of $teachers, not an independent roster - see addReferentTeacher()/
    // ProgramSettingsController's referent-tab endpoints, which only ever add a user here after
    // checking $teachers already contains them.
    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'program_referent_teacher')]
    private Collection $referentTeachers;

    /** @var Collection<int, LessonSession> */
    #[ORM\OneToMany(mappedBy: 'program', targetEntity: LessonSession::class)]
    private Collection $lessonSessions;

    /** @var Collection<int, Topic> */
    #[ORM\OneToMany(mappedBy: 'program', targetEntity: Topic::class)]
    private Collection $topics;

    /** @var Collection<int, TopicGroup> */
    #[ORM\OneToMany(mappedBy: 'program', targetEntity: TopicGroup::class)]
    private Collection $topicGroups;

    /** @var Collection<int, ProgramFinancialItem> */
    #[ORM\OneToMany(mappedBy: 'program', targetEntity: ProgramFinancialItem::class)]
    private Collection $financialItems;

    /** @var Collection<int, ProgramReport> */
    #[ORM\OneToMany(mappedBy: 'program', targetEntity: ProgramReport::class)]
    private Collection $reports;

    // Marks this Program as throwaway/demo data (fake students, tutors, enterprises...) staff can
    // exercise the platform against mid-year without confusing it for real data - unlike the
    // flags below, this doesn't gate a feature area, it's a data-classification flag surfaced as
    // a warning banner (templates/layout/app.html.twig) whenever browsing this Program's pages.
    #[ORM\Column(name: 'test_program', options: ['default' => false])]
    private bool $testProgram = false;

    // Gate the nav/settings-tab entries for their respective feature areas - on by default so a
    // freshly created Program starts with everything available (see ProgramType's checkbox
    // fields and ProgramFeatureGuardTrait's use in the controllers that serve these areas).
    #[ORM\Column(name: 'timetable_management_enabled', options: ['default' => true])]
    private bool $timetableManagementEnabled = true;

    #[ORM\Column(name: 'financial_management_enabled', options: ['default' => true])]
    private bool $financialManagementEnabled = true;

    #[ORM\Column(name: 'internship_management_enabled', options: ['default' => true])]
    private bool $internshipManagementEnabled = true;

    #[ORM\Column(name: 'assignment_management_enabled', options: ['default' => true])]
    private bool $assignmentManagementEnabled = true;

    // Unlike the feature-area flags above, off by default - the alternance calendar PDF (same
    // Period/PeriodType visualization as the Livret Alternant's own calendar page, see
    // InternshipCalendarBuilder) is a niche export most Programs don't need to expose.
    #[ORM\Column(name: 'alternance_calendar_enabled', options: ['default' => false])]
    private bool $alternanceCalendarEnabled = false;

    // Off by default: every Program uses the Centre de formation's shared SkillLevel
    // definition (SettingsStructureController) unless it opts into fully defining its own instead
    // - see SkillLevelRepository::findAllActiveForProgramOrGlobal(), the single place
    // this flag is read. Toggled from the Program's own "Niveaux de compétences" tab, not
    // ProgramType, since it's a day-to-day content choice rather than a structural feature-area
    // gate like the flags above. Unlike skill levels, SkillGroup/Skill have no such shared/opt-out
    // mechanism - they're always this Program's own.
    #[ORM\Column(name: 'custom_skill_levels_enabled', options: ['default' => false])]
    private bool $customSkillLevelsEnabled = false;

    public function __construct(string $name, string $shortName, Cohort $cohort, SchoolYear $schoolYear)
    {
        $this->name = $name;
        $this->shortName = $shortName;
        $this->creationDate = new \DateTimeImmutable();
        $this->options = new ArrayCollection();
        $this->modalities = new ArrayCollection();
        $this->students = new ArrayCollection();
        $this->teachers = new ArrayCollection();
        $this->referentTeachers = new ArrayCollection();
        $this->lessonSessions = new ArrayCollection();
        $this->topics = new ArrayCollection();
        $this->topicGroups = new ArrayCollection();
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

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    // The date the calendar/timetable should actually treat as this Program's bounds - its own
    // override if set, otherwise the SchoolYear's.
    public function getEffectiveStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate ?? $this->schoolYear?->getStartDate();
    }

    public function getEffectiveEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate ?? $this->schoolYear?->getEndDate();
    }

    #[Assert\Callback]
    public function validateDateRange(ExecutionContextInterface $context): void
    {
        if (null !== $this->startDate && null !== $this->endDate && $this->endDate <= $this->startDate) {
            $context->buildViolation('programEndDateBeforeStartDateMessage')
                ->atPath('endDate')
                ->addViolation();
        }
    }

    public function getPeriodGroup(): ?PeriodGroup
    {
        return $this->periodGroup;
    }

    public function setPeriodGroup(?PeriodGroup $periodGroup): static
    {
        $this->periodGroup = $periodGroup;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a fresh
        // query, not automatically from setting the owning side.
        if (null !== $periodGroup && !$periodGroup->getPrograms()->contains($this)) {
            $periodGroup->getPrograms()->add($this);
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

    /** @return Collection<int, User> */
    public function getReferentTeachers(): Collection
    {
        return $this->referentTeachers;
    }

    public function addReferentTeacher(User $referentTeacher): static
    {
        if (!$this->referentTeachers->contains($referentTeacher)) {
            $this->referentTeachers->add($referentTeacher);
        }

        return $this;
    }

    public function removeReferentTeacher(User $referentTeacher): static
    {
        $this->referentTeachers->removeElement($referentTeacher);

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

    /** @return Collection<int, TopicGroup> */
    public function getTopicGroups(): Collection
    {
        return $this->topicGroups;
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

    public function isInternshipManagementEnabled(): bool
    {
        return $this->internshipManagementEnabled;
    }

    public function setInternshipManagementEnabled(bool $internshipManagementEnabled): static
    {
        $this->internshipManagementEnabled = $internshipManagementEnabled;

        return $this;
    }

    public function isAssignmentManagementEnabled(): bool
    {
        return $this->assignmentManagementEnabled;
    }

    public function setAssignmentManagementEnabled(bool $assignmentManagementEnabled): static
    {
        $this->assignmentManagementEnabled = $assignmentManagementEnabled;

        return $this;
    }

    public function isAlternanceCalendarEnabled(): bool
    {
        return $this->alternanceCalendarEnabled;
    }

    public function setAlternanceCalendarEnabled(bool $alternanceCalendarEnabled): static
    {
        $this->alternanceCalendarEnabled = $alternanceCalendarEnabled;

        return $this;
    }

    public function isCustomSkillLevelsEnabled(): bool
    {
        return $this->customSkillLevelsEnabled;
    }

    public function setCustomSkillLevelsEnabled(bool $customSkillLevelsEnabled): static
    {
        $this->customSkillLevelsEnabled = $customSkillLevelsEnabled;

        return $this;
    }

    public function isTestProgram(): bool
    {
        return $this->testProgram;
    }

    public function setTestProgram(bool $testProgram): static
    {
        $this->testProgram = $testProgram;

        return $this;
    }
}
