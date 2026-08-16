<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use App\Enum\LessonLogSection;
use App\Enum\SelfAssessmentFeedback;
use App\Repository\AssignmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A generic "place to submit work" - see design/validated/assignment-submission-box.md. Not tied
 * to a LessonSession (part A optionally links to one from its travail avant/après slots, but
 * Assignment itself has no idea it's being used that way). Hard-deleted like LessonSession (no
 * inactiveDate lifecycle) - AuditableTrait is used only for createdBy/lastUpdatedBy tracking,
 * same as InternshipProgramInfo.
 */
#[ORM\Entity(repositoryClass: AssignmentRepository::class)]
#[ORM\Table(name: 'assignment')]
class Assignment implements AccessConditionHost
{
    use AccessConditionTrait;
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // Timestamped since the cahier de texte (« pour mar. 04 août · 08:00 »): an end-of-day deadline
    // was no longer precise enough once an assignment is given from one séance to the next.
    // Earlier assignments were carried over at 23:59, which was what the bare date meant.
    #[ORM\Column(name: 'due_date', type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    #[ORM\Column(name: 'audience_type', length: 20, enumType: AssignmentAudienceType::class)]
    #[Assert\NotNull]
    private ?AssignmentAudienceType $audienceType = null;

    // ToSubmit mirrors the pre-nature behavior (file submission box); the other natures are
    // announce-only - see AssignmentNature and ProgramAssignmentSubmissionController.
    #[ORM\Column(length: 20, enumType: AssignmentNature::class)]
    #[Assert\NotNull]
    private AssignmentNature $nature = AssignmentNature::ToSubmit;

    // Populated only when $audienceType is Option - cleared by the controller otherwise. A
    // student is in the audience if they hold ANY of the selected options (union, not
    // intersection) - see App\Service\AssignmentAudienceResolver.
    /** @var Collection<int, Option> */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'assignment_option')]
    private Collection $options;

    // Populated only when $audienceType is Manual - cleared by the controller otherwise. Same
    // unidirectional ManyToMany shape as Program::$students (no inverse side on User).
    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'assignment_manual_recipient')]
    private Collection $manualRecipients;

    /**
     * The slot the assignment comes from, when it was given from a cahier de texte (2b), and the
     * part it hangs off there. Null for an assignment created by the historical screen, which knows
     * no séance - hence the optional attachment rather than a separate entity.
     *
     * It is this link that makes « séance du 04 août » appear under the assignment on the student
     * side, and that allows reopening the séance for an absent student (mockup 4a).
     */
    #[ORM\ManyToOne(targetEntity: LessonSession::class)]
    #[ORM\JoinColumn(name: 'lesson_session_id', nullable: true, onDelete: 'SET NULL')]
    private ?LessonSession $lessonSession = null;

    #[ORM\Column(name: 'lesson_log_section', length: 20, nullable: true, enumType: LessonLogSection::class)]
    private ?LessonLogSection $lessonLogSection = null;

    /**
     * Formats accepted on submission (« PDF », « ZIP », or none = any format). A list rather than a
     * single type: the mockup offers them as a multiple selection.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'accepted_formats', type: Types::JSON)]
    private array $acceptedFormats = [];

    /**
     * From when the assignment is readable by students. Null = never published yet (the « visible
     * dès l'enregistrement » switch of 2b, left closed).
     */
    #[ORM\Column(name: 'visible_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $visibleAt = null;

    /**
     * The quiz this assignment asks to run, for the Quiz nature - and only it. A quiz instance,
     * therefore a quiz already launched on the program with its questions frozen, and not a library
     * template: this is the object the student can open.
     *
     * Quizzes in Live mode are excluded from the choice: a live contest is run together, at the
     * appointed time; it is not given out to do for next time.
     */
    #[ORM\ManyToOne(targetEntity: QuizInstance::class)]
    #[ORM\JoinColumn(name: 'quiz_instance_id', nullable: true, onDelete: 'SET NULL')]
    private ?QuizInstance $quizInstance = null;

    /**
     * The share of correct answers a student must reach for the quiz to count as done (2a,
     * « Objectif minimum »). Null = no target, concluding the quiz is enough.
     *
     * Decimal because the mockup writes "70,0 %": half a point out of twenty questions falls below
     * the integer. Stored as a percentage rather than points, a quiz's total varying from one
     * instance to the next.
     */
    #[ORM\Column(name: 'minimum_score_percent', type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    #[Assert\Range(min: 0, max: 100)]
    private ?string $minimumScorePercent = null;

    /**
     * The audio recording this assignment asks to listen to, for the Listening nature - and only it.
     * Same shape as $quizInstance above: the assignment names the object the student opens, without
     * owning it.
     *
     * The link is also read the other way round (AudioRecording::$assignment): it is what moves the
     * recording to the "Travail créé" status. On SET NULL the recording therefore falls back to
     * "Complet" and can give an assignment again, which is exactly what a deleted assignment should
     * mean.
     */
    #[ORM\ManyToOne(targetEntity: AudioRecording::class)]
    #[ORM\JoinColumn(name: 'audio_recording_id', nullable: true, onDelete: 'SET NULL')]
    private ?AudioRecording $audioRecording = null;

    /**
     * The video this assignment asks to watch, for the Watching nature - and only it. The exact
     * counterpart of $audioRecording above, read the other way round too
     * (VideoResource::$assignment), which is what moves the video to the "Travail créé" status.
     */
    #[ORM\ManyToOne(targetEntity: VideoResource::class)]
    #[ORM\JoinColumn(name: 'video_resource_id', nullable: true, onDelete: 'SET NULL')]
    private ?VideoResource $videoResource = null;

    /**
     * The gradebook evaluation the student must estimate, for the SelfAssessment nature - and only
     * it. Same shape as $quizInstance above: the assignment designates the object the student opens,
     * without owning it.
     */
    #[ORM\ManyToOne(targetEntity: Evaluation::class)]
    #[ORM\JoinColumn(name: 'evaluation_id', nullable: true, onDelete: 'SET NULL')]
    private ?Evaluation $evaluation = null;

    /**
     * What the student gets once their estimate is submitted. Null outside the SelfAssessment nature.
     */
    #[ORM\Column(name: 'self_assessment_feedback', type: Types::STRING, length: 20, nullable: true, enumType: SelfAssessmentFeedback::class)]
    private ?SelfAssessmentFeedback $selfAssessmentFeedback = null;

    /**
     * The assignment's matière, when it can be inferred from the entry point (the séance it is given
     * from, or the single matière the teacher covers in the class). Never asked of the teacher - the
     * attachment is determined automatically (design_handoff_creation_travail, product rules) - so
     * null when nothing settles it, and the assignment is then read with no matière mentioned.
     */
    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', nullable: true, onDelete: 'SET NULL')]
    private ?Topic $topic = null;

    /**
     * The group batch targeted, for the GroupBatch targeting only. A batch is a frozen snapshot of
     * the groups' make-up (see GroupBatch): targeting the batch, and not recomputed groups,
     * guarantees the assignment's audience no longer moves after publication.
     */
    #[ORM\ManyToOne(targetEntity: GroupBatch::class)]
    #[ORM\JoinColumn(name: 'group_batch_id', nullable: true, onDelete: 'SET NULL')]
    private ?GroupBatch $groupBatch = null;

    // Character of the assignment (step 2 of 2a): mandatory by default, optional on request.
    #[ORM\Column]
    private bool $mandatory = true;

    /**
     * « Noté » (default) / « Non noté ». A graded assignment gives birth to a gradebook evaluation
     * when the submissions come in - see App\Service\AssignmentGradebookLinker, which creates it and
     * stores it in $gradebookEvaluation.
     */
    #[ORM\Column]
    private bool $graded = true;

    // Whether the grading choice is readable on the student side. Editable afterwards, hence a
    // separate field rather than an inference from $graded.
    #[ORM\Column(name: 'grading_visible_to_students')]
    private bool $gradingVisibleToStudents = true;

    /**
     * The gradebook evaluation created automatically for this graded assignment, once the first
     * submissions have arrived. Distinct from $evaluation, which on the contrary designates an
     * existing evaluation the student must estimate (Autoévaluation nature): here the assignment is
     * the source of the grade, there it is its mirror.
     */
    #[ORM\ManyToOne(targetEntity: Evaluation::class)]
    #[ORM\JoinColumn(name: 'gradebook_evaluation_id', nullable: true, onDelete: 'SET NULL')]
    private ?Evaluation $gradebookEvaluation = null;

    /**
     * Late submission allowed (Dépôt nature only). Closed by default. No time limit when it is open:
     * the submission is simply flagged « en retard » in the follow-up.
     */
    #[ORM\Column(name: 'late_submission_allowed')]
    private bool $lateSubmissionAllowed = false;

    /**
     * Read tracking (À lire nature only): the teacher sees who opened the assignment. The fact is
     * already recorded for everyone by AssignmentView; this flag only says whether it is reported as
     * progress on the assignment list.
     */
    #[ORM\Column(name: 'read_tracking_enabled')]
    private bool $readTrackingEnabled = true;

    /** @var Collection<int, AssignmentExpectedProduction> */
    #[ORM\OneToMany(mappedBy: 'assignment', targetEntity: AssignmentExpectedProduction::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $expectedProductions;

    /** @var Collection<int, AssignmentAttachment> */
    #[ORM\OneToMany(mappedBy: 'assignment', targetEntity: AssignmentAttachment::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $attachments;

    public function __construct(Program $program)
    {
        $this->manualRecipients = new ArrayCollection();
        $this->options = new ArrayCollection();
        $this->expectedProductions = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->program = $program;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    // The class is set in the constructor for every entry point that already knows it; the 2a wizard,
    // by contrast, has it chosen at step 1 and sets it here before publication.
    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        return $this;
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

    public function getGroupBatch(): ?GroupBatch
    {
        return $this->groupBatch;
    }

    public function setGroupBatch(?GroupBatch $groupBatch): static
    {
        $this->groupBatch = $groupBatch;

        return $this;
    }

    public function isMandatory(): bool
    {
        return $this->mandatory;
    }

    public function setMandatory(bool $mandatory): static
    {
        $this->mandatory = $mandatory;

        return $this;
    }

    public function isGraded(): bool
    {
        return $this->graded;
    }

    public function setGraded(bool $graded): static
    {
        $this->graded = $graded;

        return $this;
    }

    public function isGradingVisibleToStudents(): bool
    {
        return $this->gradingVisibleToStudents;
    }

    public function setGradingVisibleToStudents(bool $visible): static
    {
        $this->gradingVisibleToStudents = $visible;

        return $this;
    }

    public function getGradebookEvaluation(): ?Evaluation
    {
        return $this->gradebookEvaluation;
    }

    public function setGradebookEvaluation(?Evaluation $evaluation): static
    {
        $this->gradebookEvaluation = $evaluation;

        return $this;
    }

    public function isLateSubmissionAllowed(): bool
    {
        return $this->lateSubmissionAllowed;
    }

    public function setLateSubmissionAllowed(bool $allowed): static
    {
        $this->lateSubmissionAllowed = $allowed;

        return $this;
    }

    public function isReadTrackingEnabled(): bool
    {
        return $this->readTrackingEnabled;
    }

    public function setReadTrackingEnabled(bool $enabled): static
    {
        $this->readTrackingEnabled = $enabled;

        return $this;
    }

    /** @return Collection<int, AssignmentExpectedProduction> */
    public function getExpectedProductions(): Collection
    {
        return $this->expectedProductions;
    }

    public function addExpectedProduction(AssignmentExpectedProduction $production): static
    {
        if (!$this->expectedProductions->contains($production)) {
            $this->expectedProductions->add($production);
            $production->setAssignment($this);
        }

        return $this;
    }

    public function removeExpectedProduction(AssignmentExpectedProduction $production): static
    {
        $this->expectedProductions->removeElement($production);

        return $this;
    }

    /** @return Collection<int, AssignmentAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    /**
     * The assignment announces several dates: at least one production has a deadline of its own.
     * This is what triggers the amber banner of step 4 and the « échéances multiples » mention in
     * the list.
     */
    public function hasMultipleDueDates(): bool
    {
        foreach ($this->expectedProductions as $production) {
            if ($production->hasOwnDueDate()) {
                return true;
            }
        }

        return false;
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

    public function getLessonLogSection(): ?LessonLogSection
    {
        return $this->lessonLogSection;
    }

    public function setLessonLogSection(?LessonLogSection $lessonLogSection): static
    {
        $this->lessonLogSection = $lessonLogSection;

        return $this;
    }

    /** @return list<string> */
    public function getAcceptedFormats(): array
    {
        return $this->acceptedFormats;
    }

    /** @param array<array-key, string> $acceptedFormats re-indexed on the way in: this is a JSON column, and a gap in the keys would store an object */
    public function setAcceptedFormats(array $acceptedFormats): static
    {
        $this->acceptedFormats = array_values($acceptedFormats);

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

    public function getEvaluation(): ?Evaluation
    {
        return $this->evaluation;
    }

    public function setEvaluation(?Evaluation $evaluation): static
    {
        $this->evaluation = $evaluation;

        return $this;
    }

    public function getSelfAssessmentFeedback(): ?SelfAssessmentFeedback
    {
        return $this->selfAssessmentFeedback;
    }

    public function setSelfAssessmentFeedback(?SelfAssessmentFeedback $feedback): static
    {
        $this->selfAssessmentFeedback = $feedback;

        return $this;
    }

    // The comparison screen (5c) only exists if the teacher shared their grading.
    public function sharesTeacherGrade(): bool
    {
        return SelfAssessmentFeedback::Comparison === $this->selfAssessmentFeedback;
    }

    public function getQuizInstance(): ?QuizInstance
    {
        return $this->quizInstance;
    }

    public function setQuizInstance(?QuizInstance $quizInstance): static
    {
        $this->quizInstance = $quizInstance;

        return $this;
    }

    public function getAudioRecording(): ?AudioRecording
    {
        return $this->audioRecording;
    }

    public function setAudioRecording(?AudioRecording $audioRecording): static
    {
        $this->audioRecording = $audioRecording;

        return $this;
    }

    public function getVideoResource(): ?VideoResource
    {
        return $this->videoResource;
    }

    public function setVideoResource(?VideoResource $videoResource): static
    {
        $this->videoResource = $videoResource;

        return $this;
    }

    public function getMinimumScorePercent(): ?float
    {
        return null === $this->minimumScorePercent ? null : (float) $this->minimumScorePercent;
    }

    public function setMinimumScorePercent(?float $percent): static
    {
        $this->minimumScorePercent = null === $percent ? null : number_format($percent, 2, '.', '');

        return $this;
    }

    /**
     * Whether a score meets the announced target. With no target, concluding the quiz is enough -
     * the behavior that predates this field, which existing assignments keep.
     */
    public function reachesMinimumScore(?float $scorePercent): bool
    {
        $minimum = $this->getMinimumScorePercent();

        if (null === $minimum) {
            return true;
        }

        return null !== $scorePercent && $scorePercent >= $minimum;
    }

    public function isVisibleFor(?\DateTimeImmutable $now = null): bool
    {
        return null !== $this->visibleAt && $this->visibleAt <= ($now ?? new \DateTimeImmutable());
    }

    public function getAudienceType(): ?AssignmentAudienceType
    {
        return $this->audienceType;
    }

    public function setAudienceType(?AssignmentAudienceType $audienceType): static
    {
        $this->audienceType = $audienceType;

        return $this;
    }

    public function getNature(): AssignmentNature
    {
        return $this->nature;
    }

    public function setNature(AssignmentNature $nature): static
    {
        $this->nature = $nature;

        return $this;
    }

    public function expectsSubmission(): bool
    {
        return $this->nature->expectsSubmission();
    }

    /** @return Collection<int, Option> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(Option $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
        }

        return $this;
    }

    public function removeOption(Option $option): static
    {
        $this->options->removeElement($option);

        return $this;
    }

    /** @return Collection<int, User> */
    public function getManualRecipients(): Collection
    {
        return $this->manualRecipients;
    }

    public function addManualRecipient(User $recipient): static
    {
        if (!$this->manualRecipients->contains($recipient)) {
            $this->manualRecipients->add($recipient);
        }

        return $this;
    }

    public function removeManualRecipient(User $recipient): static
    {
        $this->manualRecipients->removeElement($recipient);

        return $this;
    }

    // A submission strictly after this instant counts as late - end-of-due-date, not the exact
    // due_date midnight boundary (a student submitting at 23:00 on the due date is on time).
    public function isLate(\DateTimeImmutable $submittedAt): bool
    {
        return $submittedAt > $this->dueDate->modify('+1 day');
    }

    public function getAccessConditionProgram(): ?Program
    {
        return $this->program;
    }

    public function getAccessConditionLabel(): string
    {
        return $this->title ?? '';
    }
}
