<?php

namespace App\Entity;

use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use App\Enum\LessonLogSection;
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

    public function __construct(Program $program)
    {
        $this->manualRecipients = new ArrayCollection();
        $this->options = new ArrayCollection();
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

    public function getQuizInstance(): ?QuizInstance
    {
        return $this->quizInstance;
    }

    public function setQuizInstance(?QuizInstance $quizInstance): static
    {
        $this->quizInstance = $quizInstance;

        return $this;
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
