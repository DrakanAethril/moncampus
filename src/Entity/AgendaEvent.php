<?php

namespace App\Entity;

use App\Enum\MessageAudienceType;
use App\Repository\AgendaEventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A one-off institution event (réunion, sortie, tournoi...) - deliberately distinct from
 * LessonSession/the per-Program timetable (see design/design_campus_manager's "Agenda —
 * événements (distinct de l'emploi du temps)" dashboard widget): this is for things that aren't a
 * class's regular course schedule. Audience-targeted the same way as Announcement/MessageThread -
 * see App\Entity\AudienceTargetable.
 */
#[ORM\Entity(repositoryClass: AgendaEventRepository::class)]
#[ORM\Table(name: 'agenda_event')]
class AgendaEvent implements AudienceTargetable
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

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'start_at', type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startAt = null;

    // Nullable - some events are a single point in time (e.g. "18h00", no announced end), matching
    // the reference design's own mix of ranged and point events.
    #[ORM\Column(name: 'end_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endAt = null;

    // Free text, not a Room relation - the reference design's events mix real rooms ("Salle
    // polyvalente") with places that aren't in the Room roster at all ("Cour d'honneur",
    // "Gymnase"), so a free field covers both without forcing every location to exist as a Room
    // first.
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $location = null;

    #[ORM\Column(name: 'audience_type', length: 20, enumType: MessageAudienceType::class)]
    #[Assert\NotNull]
    private ?MessageAudienceType $audienceType = null;

    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class)]
    #[ORM\JoinTable(name: 'agenda_event_program')]
    private Collection $programs;

    // Independent, not mutually exclusive - a Program audience can include either role, or both at
    // once (e.g. "students and teachers of Program A and B"), see AudienceTargetable's docblock.
    #[ORM\Column(name: 'include_students')]
    private bool $includeStudents = true;

    #[ORM\Column(name: 'include_teachers')]
    private bool $includeTeachers = true;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'agenda_event_manual_recipient')]
    private Collection $manualRecipients;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    // Optional - an event doesn't need a sign-up sheet at all. Unidirectional: SignupList doesn't
    // hold a collection back (a list has at most one parent across AgendaEvent/Announcement/
    // MessageThread combined, so a reverse collection here would be one-or-empty and pointless -
    // SignupListController resolves "which parent, if any" on demand via a cheap indexed lookup on
    // each of the three repositories instead).
    #[ORM\ManyToOne(targetEntity: SignupList::class)]
    #[ORM\JoinColumn(name: 'signup_list_id', nullable: true, onDelete: 'SET NULL')]
    private ?SignupList $signupList = null;

    public function __construct()
    {
        $this->programs = new ArrayCollection();
        $this->manualRecipients = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStartAt(): ?\DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(?\DateTimeImmutable $startAt): static
    {
        $this->startAt = $startAt;

        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(?\DateTimeImmutable $endAt): static
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

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

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getSignupList(): ?SignupList
    {
        return $this->signupList;
    }

    public function setSignupList(?SignupList $signupList): static
    {
        $this->signupList = $signupList;

        return $this;
    }

    public function isPast(): bool
    {
        return ($this->endAt ?? $this->startAt) < new \DateTimeImmutable();
    }
}
