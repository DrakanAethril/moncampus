<?php

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
class Assignment
{
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

    // Horodatée depuis le cahier de texte (« pour mar. 04 août · 08:00 ») : une échéance de fin de
    // journée n'était plus assez précise dès lors qu'un travail se donne d'une séance à l'autre.
    // Les devoirs antérieurs ont été repris à 23:59, qui était le sens de la date seule.
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
     * Le créneau d'où vient le travail, quand il a été donné depuis un cahier de texte (2b), et le
     * temps auquel il s'y rattache. Nuls pour un devoir créé par l'écran devoir historique, qui ne
     * connaît aucune séance - d'où le rattachement facultatif plutôt qu'une entité séparée.
     *
     * C'est ce lien qui fait apparaître « séance du 04 août » sous le travail chez l'étudiant, et
     * qui permet de rouvrir la séance pour un absent (maquette 4a).
     */
    #[ORM\ManyToOne(targetEntity: LessonSession::class)]
    #[ORM\JoinColumn(name: 'lesson_session_id', nullable: true, onDelete: 'SET NULL')]
    private ?LessonSession $lessonSession = null;

    #[ORM\Column(name: 'lesson_log_section', length: 20, nullable: true, enumType: LessonLogSection::class)]
    private ?LessonLogSection $lessonLogSection = null;

    /**
     * Formats acceptés au dépôt (« PDF », « ZIP », ou aucun = tout format). Une liste plutôt qu'un
     * type unique : la maquette les donne en sélection multiple.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'accepted_formats', type: Types::JSON)]
    private array $acceptedFormats = [];

    /**
     * À partir de quand le travail est lisible par les étudiants. Null = jamais encore publié
     * (l'interrupteur « visible dès l'enregistrement » du 2b, laissé fermé).
     */
    #[ORM\Column(name: 'visible_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $visibleAt = null;

    /**
     * Le quiz que ce travail demande de dérouler, pour la nature Quiz - et seulement elle. Une
     * instance de quiz, donc un quiz déjà lancé sur la formation avec ses questions figées, et non
     * un modèle de la bibliothèque : c'est l'objet que l'étudiant peut ouvrir.
     *
     * Les quiz en mode Live sont exclus du choix : un concours se déroule ensemble, à l'heure dite,
     * il ne se donne pas à faire pour la prochaine fois.
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
     * L'évaluation du carnet de notes que l'étudiant doit estimer, pour la nature SelfAssessment -
     * et seulement elle. Même forme que $quizInstance ci-dessus : le travail désigne l'objet que
     * l'étudiant ouvre, sans le posséder.
     */
    #[ORM\ManyToOne(targetEntity: Evaluation::class)]
    #[ORM\JoinColumn(name: 'evaluation_id', nullable: true, onDelete: 'SET NULL')]
    private ?Evaluation $evaluation = null;

    /**
     * Ce que l'étudiant reçoit une fois son estimation validée. Null hors nature SelfAssessment.
     */
    #[ORM\Column(name: 'self_assessment_feedback', type: Types::STRING, length: 20, nullable: true, enumType: SelfAssessmentFeedback::class)]
    private ?SelfAssessmentFeedback $selfAssessmentFeedback = null;

    /**
     * La matière du travail, quand elle se déduit du point d'entrée (la séance d'où il est donné,
     * ou l'unique matière que l'enseignant assure dans la classe). Jamais demandée à l'enseignant -
     * le rattachement est déterminé automatiquement (design_handoff_creation_travail, règles
     * produit) - donc nulle quand rien ne permet de trancher, et le travail se lit alors sans
     * mention de matière.
     */
    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', nullable: true, onDelete: 'SET NULL')]
    private ?Topic $topic = null;

    /**
     * Le lot de groupes visé, pour le seul ciblage GroupBatch. Un lot est un instantané figé de la
     * composition des groupes (voir GroupBatch) : viser le lot, et non des groupes recalculés,
     * garantit que le public du travail ne bouge plus après sa publication.
     */
    #[ORM\ManyToOne(targetEntity: GroupBatch::class)]
    #[ORM\JoinColumn(name: 'group_batch_id', nullable: true, onDelete: 'SET NULL')]
    private ?GroupBatch $groupBatch = null;

    // Caractère du travail (étape 2 du 2a) : obligatoire par défaut, facultatif à la demande.
    #[ORM\Column]
    private bool $mandatory = true;

    /**
     * « Noté » (défaut) / « Non noté ». Un travail noté fait naître une évaluation au carnet à la
     * réception des rendus - voir App\Service\AssignmentGradebookLinker, qui la crée et la range
     * dans $gradebookEvaluation.
     */
    #[ORM\Column]
    private bool $graded = true;

    // Si le choix de notation se lit côté étudiant. Modifiable après coup, d'où un champ à part
    // plutôt qu'une déduction de $graded.
    #[ORM\Column(name: 'grading_visible_to_students')]
    private bool $gradingVisibleToStudents = true;

    /**
     * L'évaluation du carnet créée automatiquement pour ce travail noté, une fois les premiers
     * rendus arrivés. Distincte de $evaluation, qui désigne au contraire une évaluation existante
     * que l'étudiant doit estimer (nature Autoévaluation) : ici le travail est la source de la
     * note, là il en est le miroir.
     */
    #[ORM\ManyToOne(targetEntity: Evaluation::class)]
    #[ORM\JoinColumn(name: 'gradebook_evaluation_id', nullable: true, onDelete: 'SET NULL')]
    private ?Evaluation $gradebookEvaluation = null;

    /**
     * Dépôt en retard autorisé (nature Dépôt uniquement). Fermé par défaut. Aucune limite de temps
     * quand il est ouvert : le rendu est simplement signalé « en retard » dans le suivi.
     */
    #[ORM\Column(name: 'late_submission_allowed')]
    private bool $lateSubmissionAllowed = false;

    /**
     * Suivi de lecture (nature À lire uniquement) : l'enseignant voit qui a ouvert le travail. Le
     * fait est déjà enregistré pour tous par AssignmentView ; ce drapeau dit seulement s'il est
     * remonté comme avancement sur la liste des travaux.
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

    // La classe est posée au constructeur pour tout point d'entrée qui la connaît déjà ; l'assistant
    // du 2a, lui, la fait choisir à l'étape 1 et la repose ici avant publication.
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
     * Le travail annonce plusieurs dates : au moins une production a son échéance propre. C'est ce
     * qui déclenche le bandeau ambre de l'étape 4 et la mention « échéances multiples » en liste.
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

    /** @param list<string> $acceptedFormats */
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

    // L'écran comparé (5c) n'existe que si l'enseignant a partagé sa notation.
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
}
