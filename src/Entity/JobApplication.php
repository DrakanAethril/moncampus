<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\JobApplicationOrigin;
use App\Repository\JobApplicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One "démarche" of a student: everything they exchanged around a single job hunt
 * (design_handoff_stage_alternance, screens 2a and 2b, which group mails by démarche).
 *
 * The student names it themselves when writing their first mail ("Néopixel", "mairie - service
 * info"), or keeps a company from « Trouver une entreprise », which opens a démarche with nothing
 * sent yet - « à écrire » (design/validated/vivier-entreprises.md §6). It is deliberately **not**
 * an App\Entity\Enterprise: the vivier is a shared record of the establishment, a démarche is the
 * student's own track of who they wrote to, under whatever name makes sense to them. A démarche may
 * point at an employer of the vivier ($enterprise) and at an establishment of the register
 * ($siret) - only ever by the student's own gesture.
 *
 * Grouping happens per démarche and not per mail: a send, its follow-up and the reply received all
 * belong to the same one. The démarche carries the context (position, contact); the
 * App\Entity\EmailMessage rows are only its traces.
 *
 * **No progress status.** The handoff forbids it explicitly (principle #1): the platform gathers
 * mails, it does not sort them. No "offer", no "rejection", no "interview". What the screens show
 * ("delivered, no reply", "Reply received on 15/09") is **derived** from the mails and their SES
 * events, and is never stored as a verdict.
 */
#[ORM\Entity(repositoryClass: JobApplicationRepository::class)]
#[ORM\Table(name: 'job_application')]
#[ORM\Index(name: 'idx_job_application_student', columns: ['student_id'])]
// A name is unique per student *and per program*: the same student redoing a year, or moving up to
// the next one, starts a fresh set of démarches rather than reopening last year's.
#[ORM\UniqueConstraint(name: 'uniq_job_application_name', columns: ['student_id', 'program_id', 'name'])]
class JobApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?User $student = null;

    /**
     * The class the démarche was opened from, resolved from the student's active programs at
     * creation time. Nullable because a student can be between two enrolments and still have to be
     * able to write: the démarche is then simply outside any class, and unicity falls back to the
     * name alone for that student.
     */
    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: true, onDelete: 'SET NULL')]
    private ?Program $program = null;

    /** The name the student gave it - what every screen groups and labels on. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    /** The position aimed at, as the student words it ("Web developer (apprenticeship)"). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $position = null;

    /** The contact inside the company, shown next to the position on the teacher's sheet. */
    #[ORM\Column(name: 'contact_name', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $contactName = null;

    #[ORM\Column(length: 20, enumType: JobApplicationOrigin::class)]
    private JobApplicationOrigin $origin = JobApplicationOrigin::Spontaneous;

    /**
     * The establishment of the État's register this démarche is about - set only by a gesture of
     * the student (« Garder » on « Trouver une entreprise », or « Rattacher » on a fiche), never
     * guessed for a démarche named by hand (design/validated/vivier-entreprises.md §6.2). It is how
     * a result line says « dans mes démarches » and how a closed search feeds the vivier.
     */
    #[ORM\Column(length: 14, nullable: true)]
    private ?string $siret = null;

    /** The employer of the vivier carrying that SIRET, when the establishment knows it. */
    #[ORM\ManyToOne(targetEntity: Enterprise::class)]
    #[ORM\JoinColumn(name: 'enterprise_id', nullable: true, onDelete: 'SET NULL')]
    private ?Enterprise $enterprise = null;

    /**
     * The student's own note - where they found a contact, when to call back. **Read by their
     * teachers** (D9), and the screen says so under the field; the team's own notes about the
     * student stay in JobSearchNote, which the student never reads.
     */
    #[ORM\Column(name: 'student_note', type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $studentNote = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, EmailMessage>
     *
     * Sends, follow-ups and replies of this application, in both directions. Inbound replies land
     * here without anyone being asked a thing: they inherit the application of the mail they answer,
     * through In-Reply-To/References (handoff principle #5).
     */
    #[ORM\OneToMany(mappedBy: 'jobApplication', targetEntity: EmailMessage::class)]
    #[ORM\OrderBy(['messageDate' => 'ASC'])]
    private Collection $emailMessages;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->emailMessages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function setStudent(?User $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function setContactName(?string $contactName): static
    {
        $this->contactName = $contactName;

        return $this;
    }

    public function getOrigin(): JobApplicationOrigin
    {
        return $this->origin;
    }

    public function setOrigin(JobApplicationOrigin $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        $this->siret = $siret;

        return $this;
    }

    public function getEnterprise(): ?Enterprise
    {
        return $this->enterprise;
    }

    public function setEnterprise(?Enterprise $enterprise): static
    {
        $this->enterprise = $enterprise;

        return $this;
    }

    public function getStudentNote(): ?string
    {
        return $this->studentNote;
    }

    public function setStudentNote(?string $studentNote): static
    {
        $studentNote = null !== $studentNote ? trim($studentNote) : null;
        $this->studentNote = '' !== $studentNote ? $studentNote : null;

        return $this;
    }

    /**
     * « À écrire »: nothing has gone out or come in yet - a company kept from « Trouver une
     * entreprise ». Read, never stored: there is no status on a démarche (R7).
     */
    public function isToWrite(): bool
    {
        return $this->emailMessages->isEmpty();
    }

    /** @return Collection<int, EmailMessage> */
    public function getEmailMessages(): Collection
    {
        return $this->emailMessages;
    }

    /** The application's last movement, either way - what screen 2a sorts on. */
    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        $last = null;

        foreach ($this->emailMessages as $message) {
            $date = $message->getMessageDate() ?? $message->getCreatedAt();

            if (null === $last || $date > $last) {
                $last = $date;
            }
        }

        return $last;
    }

    /** A reply is simply an inbound message: no content is ever interpreted. */
    public function hasReply(): bool
    {
        foreach ($this->emailMessages as $message) {
            if (\App\Enum\EmailDirection::Inbound === $message->getDirection()) {
                return true;
            }
        }

        return false;
    }
}
