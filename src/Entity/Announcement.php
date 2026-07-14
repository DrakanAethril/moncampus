<?php

namespace App\Entity;

use App\Enum\MessageAudienceType;
use App\Repository\AnnouncementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A one-way institutional broadcast (circulaire) - see design/design_campus_manager's dashboard
 * "Annonces" widget. Deliberately a standalone entity rather than riding on MessageThread: an
 * announcement's audience is resolved live at read time (App\Service\AudienceResolver), not
 * fanned out into per-recipient rows the way MessageThreadRecipient does for messages - there is
 * no per-user "read" state to track here (the reference design never shows one), so a persistent
 * recipient table would be pure overhead. $expiresAt alone controls whether it's still "active";
 * setting it to now is how staff retracts an announcement early, there's no separate flag for
 * that.
 */
#[ORM\Entity(repositoryClass: AnnouncementRepository::class)]
#[ORM\Table(name: 'announcement')]
class Announcement implements AudienceTargetable
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

    // HugeRTE-authored HTML, sanitized server-side the same way as Message::$body - see
    // App\Controller\AnnouncementController and config/packages/html_sanitizer.yaml's
    // "app.message_body" sanitizer.
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $body = '';

    #[ORM\Column(name: 'audience_type', length: 20, enumType: MessageAudienceType::class)]
    #[Assert\NotNull]
    private ?MessageAudienceType $audienceType = null;

    // Set only for ProgramStudents/ProgramTeachers - same convention as MessageThread::$program.
    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: true)]
    private ?Program $program = null;

    // Populated only when $audienceType is Manual - same convention as
    // MessageThread::$manualRecipients.
    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'announcement_manual_recipient')]
    private Collection $manualRecipients;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct()
    {
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

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

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

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

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

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isActive(): bool
    {
        return null === $this->expiresAt || $this->expiresAt > new \DateTimeImmutable();
    }
}
