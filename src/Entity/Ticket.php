<?php

namespace App\Entity;

use App\Repository\TicketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A reported issue - a platform bug, or a problem in a classroom or on campus in general (WiFi,
 * a broken keyboard, a missing clock...). Status/priority changes and the conversation with the
 * reporter live in TicketComment rows rather than a separate history table - see that class.
 */
#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'ticket')]
class Ticket
{
    public const STATUS_OPEN = 'open';
    public const STATUS_AWAITING_INFO = 'awaiting_info';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_AWAITING_INFO,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    /** @var list<string> */
    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $subject = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $description = '';

    #[ORM\ManyToOne(targetEntity: TicketCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: false)]
    #[Assert\NotNull]
    private ?TicketCategory $category = null;

    // Nullable: not every issue is tied to one room (e.g. campus-wide WiFi). When set, this is
    // the location; otherLocation is only used as a free-text fallback when no Room fits.
    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', nullable: true)]
    private ?Room $room = null;

    #[ORM\Column(name: 'other_location', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $otherLocation = null;

    // Null when reported anonymously through the logged-out "lost access" form (see
    // PublicTicketController) - reporterName/reporterContact carry the self-reported contact
    // info for that case instead, since there's no User row to attach.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reporter_id', nullable: true)]
    private ?User $reporter = null;

    // NotBlank only enforced in the 'anonymous' validation group (see AnonymousTicketType) - the
    // authenticated TicketType form never touches these fields, and validating the whole entity
    // (Symfony's default) would otherwise make every authenticated ticket fail validation too.
    #[ORM\Column(name: 'reporter_name', length: 255, nullable: true)]
    #[Assert\NotBlank(groups: ['anonymous'])]
    #[Assert\Length(max: 255)]
    private ?string $reporterName = null;

    #[ORM\Column(name: 'reporter_contact', length: 255, nullable: true)]
    #[Assert\NotBlank(groups: ['anonymous'])]
    #[Assert\Length(max: 255)]
    private ?string $reporterContact = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assignee_id', nullable: true)]
    private ?User $assignee = null;

    #[ORM\Column(length: 255)]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(length: 255)]
    #[Assert\Choice(choices: self::PRIORITIES)]
    private string $priority = self::PRIORITY_MEDIUM;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(?User $reporter = null)
    {
        $this->reporter = $reporter;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

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

    public function getCategory(): ?TicketCategory
    {
        return $this->category;
    }

    public function setCategory(?TicketCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function setRoom(?Room $room): static
    {
        $this->room = $room;

        return $this;
    }

    public function getOtherLocation(): ?string
    {
        return $this->otherLocation;
    }

    public function setOtherLocation(?string $otherLocation): static
    {
        $this->otherLocation = $otherLocation;

        return $this;
    }

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function getReporterName(): ?string
    {
        return $this->reporterName;
    }

    public function setReporterName(?string $reporterName): static
    {
        $this->reporterName = $reporterName;

        return $this;
    }

    public function getReporterContact(): ?string
    {
        return $this->reporterContact;
    }

    public function setReporterContact(?string $reporterContact): static
    {
        $this->reporterContact = $reporterContact;

        return $this;
    }

    public function isAnonymous(): bool
    {
        return null === $this->reporter;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function setAssignee(?User $assignee): static
    {
        $this->assignee = $assignee;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;

        return $this;
    }
}
