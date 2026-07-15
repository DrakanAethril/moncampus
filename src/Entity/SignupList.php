<?php

namespace App\Entity;

use App\Enum\MessageAudienceType;
use App\Repository\SignupListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A sign-up sheet (liste d'inscription) - eligibility to register is itself audience-targeted
 * the same way Announcement/AgendaEvent/MessageThread are (see AudienceTargetable), so "who can
 * register" reuses the exact same Program/AllStudents/AllTeachers/AllStaff/Manual mechanism as
 * "who can see a message" - a deliberate reuse, not a coincidence. This is a distinct concern from
 * $registrationDeadline (whether registration is still open) and $publicRoster (whether the
 * roster of who's registered is visible beyond the creator/staff) - see App\Security\Voter\
 * SignupListVoter for how the three combine.
 *
 * Optionally attached to one AgendaEvent/Announcement/MessageThread via a nullable FK on THAT
 * side (AgendaEvent::$signupList etc.), not here - a list doesn't need to know about every
 * possible parent type, and can also stand alone with no parent at all (reachable only from its
 * own "Listes d'inscription" index).
 */
#[ORM\Entity(repositoryClass: SignupListRepository::class)]
#[ORM\Table(name: 'signup_list')]
class SignupList implements AudienceTargetable
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    // HugeRTE-authored HTML, sanitized server-side - see SignupListController and
    // config/packages/html_sanitizer.yaml's "app.signup_list_description" sanitizer.
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $description = '';

    // Null means registration never closes on its own.
    #[ORM\Column(name: 'registration_deadline', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $registrationDeadline = null;

    // Default private - only the creator/staff see who's registered unless explicitly opened up.
    // See SignupListVoter::VIEW_ROSTER.
    #[ORM\Column(name: 'public_roster')]
    private bool $publicRoster = false;

    #[ORM\Column(name: 'audience_type', length: 20, enumType: MessageAudienceType::class)]
    #[Assert\NotNull]
    private ?MessageAudienceType $audienceType = null;

    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class)]
    #[ORM\JoinTable(name: 'signup_list_program')]
    private Collection $programs;

    #[ORM\Column(name: 'include_students')]
    private bool $includeStudents = true;

    #[ORM\Column(name: 'include_teachers')]
    private bool $includeTeachers = true;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'signup_list_manual_recipient')]
    private Collection $manualRecipients;

    /** @var Collection<int, SignupListAttachment> */
    #[ORM\OneToMany(mappedBy: 'signupList', targetEntity: SignupListAttachment::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $attachments;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct()
    {
        $this->programs = new ArrayCollection();
        $this->manualRecipients = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getRegistrationDeadline(): ?\DateTimeImmutable
    {
        return $this->registrationDeadline;
    }

    public function setRegistrationDeadline(?\DateTimeImmutable $registrationDeadline): static
    {
        $this->registrationDeadline = $registrationDeadline;

        return $this;
    }

    public function isRegistrationOpen(): bool
    {
        return null === $this->registrationDeadline || $this->registrationDeadline > new \DateTimeImmutable();
    }

    public function isPublicRoster(): bool
    {
        return $this->publicRoster;
    }

    public function setPublicRoster(bool $publicRoster): static
    {
        $this->publicRoster = $publicRoster;

        return $this;
    }

    public function getAudienceType(): ?MessageAudienceType
    {
        return $this->audienceType;
    }

    public function setAudienceType(?MessageAudienceType $audienceType): static
    {
        $this->audienceType = $audienceType;

        return $this;
    }

    /** @return Collection<int, Program> */
    public function getPrograms(): Collection
    {
        return $this->programs;
    }

    public function addProgram(Program $program): static
    {
        if (!$this->programs->contains($program)) {
            $this->programs->add($program);
        }

        return $this;
    }

    public function removeProgram(Program $program): static
    {
        $this->programs->removeElement($program);

        return $this;
    }

    public function isIncludeStudents(): bool
    {
        return $this->includeStudents;
    }

    public function setIncludeStudents(bool $includeStudents): static
    {
        $this->includeStudents = $includeStudents;

        return $this;
    }

    public function isIncludeTeachers(): bool
    {
        return $this->includeTeachers;
    }

    public function setIncludeTeachers(bool $includeTeachers): static
    {
        $this->includeTeachers = $includeTeachers;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getManualRecipients(): Collection
    {
        return $this->manualRecipients;
    }

    public function addManualRecipient(User $user): static
    {
        if (!$this->manualRecipients->contains($user)) {
            $this->manualRecipients->add($user);
        }

        return $this;
    }

    public function removeManualRecipient(User $user): static
    {
        $this->manualRecipients->removeElement($user);

        return $this;
    }

    /** @return Collection<int, SignupListAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }
}
