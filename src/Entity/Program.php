<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GameTrack;
use App\Enum\ProgramAlternanceCalendarMode;
use App\Enum\ProgramSyllabusMode;
use App\Enum\VisibilityLevel;
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

    // Zero or more PeriodGroups, ordered by ProgramPeriodGroup::$priority (1 = most important) -
    // see the "Groupes de périodes" tab (Program\SettingsPeriodGroupController) for the drag-and-drop
    // reordering UI, and PeriodRepository::findAllActiveForProgram() for how priority order
    // resolves overlapping Periods between groups.
    /** @var Collection<int, ProgramPeriodGroup> */
    #[ORM\OneToMany(targetEntity: ProgramPeriodGroup::class, mappedBy: 'program', cascade: ['persist'], orphanRemoval: true)]
    private Collection $programPeriodGroups;

    // Separate from $periodGroup above (a different concept - see EvaluationPeriodGroup's
    // docblock) - which of the school's grading-period setups (if any) the Carnet de notes tool
    // should offer for this Program's evaluations.
    #[ORM\ManyToOne(targetEntity: EvaluationPeriodGroup::class, inversedBy: 'programs')]
    #[ORM\JoinColumn(name: 'evaluation_period_group_id', nullable: true)]
    private ?EvaluationPeriodGroup $evaluationPeriodGroup = null;

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
    // Program\SettingsMemberController's referent-tab endpoints, which only ever add a user here after
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

    // Whether this Program appears in the main nav at all (while active) and is offered as a
    // choice in every Program-audience picker (Message compose, SignupList, Announcement,
    // AgendaEvent, Quiz launch) - see ProgramRepository::findActiveForNav()/findAllForTeacher().
    // Defaults to Staff + Admin, unlike the other three visibility fields above which default to
    // Everyone - a freshly created Program isn't necessarily ready to be exposed to students yet.
    #[ORM\Column(length: 20, enumType: VisibilityLevel::class)]
    private VisibilityLevel $visibility = VisibilityLevel::StaffAdmin;

    // Gate the nav/settings-tab entries for their respective feature areas - on by default so a
    // freshly created Program starts with everything available (see ProgramType's checkbox
    // fields and ProgramFeatureGuardTrait's use in the controllers that serve these areas).
    #[ORM\Column(name: 'timetable_management_enabled', options: ['default' => true])]
    private bool $timetableManagementEnabled = true;

    // Who sees the "Emploi du temps" nav entry, once the flag above is on - see
    // VisibilityLevel::allowsRoles().
    #[ORM\Column(name: 'timetable_visibility', length: 20, enumType: VisibilityLevel::class)]
    private VisibilityLevel $timetableVisibility = VisibilityLevel::Everyone;

    // Independent of $timetableVisibility - the Syllabus nav entry used to piggyback on
    // $timetableManagementEnabled, which conflated two unrelated features.
    #[ORM\Column(name: 'syllabus_visibility', length: 20, enumType: VisibilityLevel::class)]
    private VisibilityLevel $syllabusVisibility = VisibilityLevel::Everyone;

    // Whether the Syllabus nav entry serves the existing Topic/TopicGroup-derived page
    // (App\Controller\ProgramSyllabusController) or an uploaded PDF ($syllabusFileKey) instead.
    #[ORM\Column(name: 'syllabus_mode', length: 20, enumType: ProgramSyllabusMode::class)]
    private ProgramSyllabusMode $syllabusMode = ProgramSyllabusMode::Topics;

    // S3 key of the uploaded syllabus PDF, only relevant when $syllabusMode is File - see
    // App\Service\FileUploadService.
    #[ORM\Column(name: 'syllabus_file_key', length: 255, nullable: true)]
    private ?string $syllabusFileKey = null;

    #[ORM\Column(name: 'financial_management_enabled', options: ['default' => true])]
    private bool $financialManagementEnabled = true;

    #[ORM\Column(name: 'internship_management_enabled', options: ['default' => true])]
    private bool $internshipManagementEnabled = true;

    /**
     * Opens the "Export TSF" tab on the program's exports screen. Off by default, unlike the
     * management toggles above: only the formations that actually keep a competency referential
     * have anything to print.
     */
    #[ORM\Column(name: 'tsf_export_enabled', options: ['default' => false])]
    private bool $tsfExportEnabled = false;

    #[ORM\Column(name: 'assignment_management_enabled', options: ['default' => true])]
    private bool $assignmentManagementEnabled = true;

    /**
     * The third axis of the feature system: the Courrier école is decided by the formation, not by
     * the role or by the person (design/validated/feature-access.md, "Le troisième axe").
     *
     * A column next to the four booleans above rather than a generic table, because there is
     * exactly one feature on this axis today - if a second turns up, that is the day to generalise,
     * and the cost of having been wrong is one migration.
     *
     * **It decides the reading, never the addresses.** App\Service\StudentMailProvisioner runs at
     * account creation, before the account is enrolled in anything, and an address that has reached
     * a company is not regenerated: a student of a closed formation has a working address whose
     * mail piles up unread, and opening the formation later reveals the whole history with nothing
     * to replay (§8.6).
     *
     * **Closed by default, and closed on every existing formation.** The Courrier école is not in
     * service in production, so there is nothing to seed and nobody loses an access the day it
     * arrives: each formation opens when the establishment decides (§12.1). The column was carried
     * open from lot 1 to lot 6 for one reason - the resolver reads this axis from the day it exists,
     * and closing it earlier would have moved a screen four lots before the lot allowed to.
     *
     * The same value on the property and in the DDL, deliberately: the column DEFAULT only lives for
     * the length of the ALTER, and a formation created by the code would otherwise disagree with
     * every formation created before it.
     */
    #[ORM\Column(name: 'school_mail_enabled', options: ['default' => false])]
    private bool $schoolMailEnabled = false;

    // Who sees the "Calendrier d'alternance" nav entry - replaces the old
    // $alternanceCalendarEnabled boolean (migrated: true -> Everyone, false -> Hidden, preserving
    // the exact prior behavior, which had no role tiering). No TeachersOnly case for this one -
    // see ProgramType's choice_filter.
    #[ORM\Column(name: 'alternance_calendar_visibility', length: 20, enumType: VisibilityLevel::class)]
    private VisibilityLevel $alternanceCalendarVisibility = VisibilityLevel::Everyone;

    // Whether the alternance calendar nav entry generates the existing PeriodGroup-derived PDF
    // (App\Controller\ProgramController::alternanceCalendarPdf()) or serves an uploaded PDF
    // ($alternanceCalendarFileKey) instead.
    #[ORM\Column(name: 'alternance_calendar_mode', length: 20, enumType: ProgramAlternanceCalendarMode::class)]
    private ProgramAlternanceCalendarMode $alternanceCalendarMode = ProgramAlternanceCalendarMode::Period;

    // S3 key of the uploaded alternance-calendar PDF, only relevant when $alternanceCalendarMode
    // is File.
    #[ORM\Column(name: 'alternance_calendar_file_key', length: 255, nullable: true)]
    private ?string $alternanceCalendarFileKey = null;

    // Off by default: every Program uses the Centre de formation's shared SkillLevel
    // definition (Settings\SkillLevelController) unless it opts into fully defining its own instead
    // - see SkillLevelRepository::findAllActiveForProgramOrGlobal(), the single place
    // this flag is read. Toggled from the Program's own "Niveaux de compétences" tab, not
    // ProgramType, since it's a day-to-day content choice rather than a structural feature-area
    // gate like the flags above. Unlike skill levels, SkillGroup/Skill have no such shared/opt-out
    // mechanism - they're always this Program's own.
    #[ORM\Column(name: 'custom_skill_levels_enabled', options: ['default' => false])]
    private bool $customSkillLevelsEnabled = false;

    /**
     * The second of the game's two switches (design/validated/gamification.md §4, decision 1).
     *
     * **Off at creation, including for a formation created after the feature was switched on
     * globally**, and in strict conjunction with App\Enum\Feature::Game: a formation that turns its
     * game on while the feature is off sees *nothing* - no menu, no screen, no calculation - and
     * switching the feature on makes the game appear in no class until one has declared itself.
     * That is what lets a pilot promo play without anybody else noticing.
     *
     * The reason the frontier exists at all is mechanical rather than pedagogical: nobody knows
     * which teachers will play. Comparing two formations would compare the involvement of their
     * teachers while pretending to compare that of their students.
     *
     * Same value on the property and in the DDL - the column DEFAULT only lives for the length of
     * the ALTER.
     */
    #[ORM\Column(name: 'game_enabled', options: ['default' => false])]
    private bool $gameEnabled = false;

    /**
     * The « univers » this formation plays in, which decides the wording of its six levels and the
     * catalogue its students draw a pseudonym from. Null is a legitimate state: generic level
     * wording and no figure catalogue - never an empty cell.
     */
    #[ORM\Column(name: 'game_track', length: 10, nullable: true, enumType: GameTrack::class)]
    private ?GameTrack $gameTrack = null;

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
        $this->programPeriodGroups = new ArrayCollection();
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

    /** @return Collection<int, ProgramPeriodGroup> */
    public function getProgramPeriodGroups(): Collection
    {
        return $this->programPeriodGroups;
    }

    public function getEvaluationPeriodGroup(): ?EvaluationPeriodGroup
    {
        return $this->evaluationPeriodGroup;
    }

    public function setEvaluationPeriodGroup(?EvaluationPeriodGroup $evaluationPeriodGroup): static
    {
        $this->evaluationPeriodGroup = $evaluationPeriodGroup;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a fresh
        // query, not automatically from setting the owning side.
        if (null !== $evaluationPeriodGroup && !$evaluationPeriodGroup->getPrograms()->contains($this)) {
            $evaluationPeriodGroup->getPrograms()->add($this);
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

    public function isTsfExportEnabled(): bool
    {
        return $this->tsfExportEnabled;
    }

    public function setTsfExportEnabled(bool $tsfExportEnabled): static
    {
        $this->tsfExportEnabled = $tsfExportEnabled;

        return $this;
    }

    public function isAssignmentManagementEnabled(): bool
    {
        return $this->assignmentManagementEnabled;
    }

    public function isSchoolMailEnabled(): bool
    {
        return $this->schoolMailEnabled;
    }

    public function isGameEnabled(): bool
    {
        return $this->gameEnabled;
    }

    public function setGameEnabled(bool $gameEnabled): static
    {
        $this->gameEnabled = $gameEnabled;

        return $this;
    }

    public function getGameTrack(): ?GameTrack
    {
        return $this->gameTrack;
    }

    public function setGameTrack(?GameTrack $gameTrack): static
    {
        $this->gameTrack = $gameTrack;

        return $this;
    }

    public function setSchoolMailEnabled(bool $schoolMailEnabled): static
    {
        $this->schoolMailEnabled = $schoolMailEnabled;

        return $this;
    }

    public function setAssignmentManagementEnabled(bool $assignmentManagementEnabled): static
    {
        $this->assignmentManagementEnabled = $assignmentManagementEnabled;

        return $this;
    }

    public function getTimetableVisibility(): VisibilityLevel
    {
        return $this->timetableVisibility;
    }

    public function setTimetableVisibility(VisibilityLevel $timetableVisibility): static
    {
        $this->timetableVisibility = $timetableVisibility;

        return $this;
    }

    public function getSyllabusVisibility(): VisibilityLevel
    {
        return $this->syllabusVisibility;
    }

    public function setSyllabusVisibility(VisibilityLevel $syllabusVisibility): static
    {
        $this->syllabusVisibility = $syllabusVisibility;

        return $this;
    }

    public function getSyllabusMode(): ProgramSyllabusMode
    {
        return $this->syllabusMode;
    }

    public function setSyllabusMode(ProgramSyllabusMode $syllabusMode): static
    {
        $this->syllabusMode = $syllabusMode;

        return $this;
    }

    public function getSyllabusFileKey(): ?string
    {
        return $this->syllabusFileKey;
    }

    public function setSyllabusFileKey(?string $syllabusFileKey): static
    {
        $this->syllabusFileKey = $syllabusFileKey;

        return $this;
    }

    public function getAlternanceCalendarVisibility(): VisibilityLevel
    {
        return $this->alternanceCalendarVisibility;
    }

    public function setAlternanceCalendarVisibility(VisibilityLevel $alternanceCalendarVisibility): static
    {
        $this->alternanceCalendarVisibility = $alternanceCalendarVisibility;

        return $this;
    }

    public function getAlternanceCalendarMode(): ProgramAlternanceCalendarMode
    {
        return $this->alternanceCalendarMode;
    }

    public function setAlternanceCalendarMode(ProgramAlternanceCalendarMode $alternanceCalendarMode): static
    {
        $this->alternanceCalendarMode = $alternanceCalendarMode;

        return $this;
    }

    public function getAlternanceCalendarFileKey(): ?string
    {
        return $this->alternanceCalendarFileKey;
    }

    public function setAlternanceCalendarFileKey(?string $alternanceCalendarFileKey): static
    {
        $this->alternanceCalendarFileKey = $alternanceCalendarFileKey;

        return $this;
    }

    public function getVisibility(): VisibilityLevel
    {
        return $this->visibility;
    }

    public function setVisibility(VisibilityLevel $visibility): static
    {
        $this->visibility = $visibility;

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

    // The one place this Program's name/shortName should ever be rendered - every other
    // getName()/getShortName() call site is expected to go through here instead, so a future
    // decoration rule only needs to change in this one spot. Plain text only (no HTML/badges):
    // this needs to work identically in PDFs, emails, and picker labels that never carry the
    // app's CSS, not just in-app HTML contexts.
    public function getDisplayName(): string
    {
        return $this->decorate($this->name);
    }

    public function getDisplayShortName(): string
    {
        return $this->decorate($this->shortName);
    }

    private function decorate(string $name): string
    {
        $decorated = $this->testProgram ? 'TEST - '.$name : $name;

        if (VisibilityLevel::Everyone !== $this->visibility) {
            $decorated .= ' - (masqué)';
        }

        return $decorated;
    }
}
