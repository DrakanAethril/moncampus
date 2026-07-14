<?php

namespace App\Entity;

use App\Enum\MessageAudienceType;
use App\Repository\MessageThreadRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The root of one conversation - see design/validated/internal-messaging.md. A thread's audience
 * is fixed at creation (App\Service\AudienceResolver resolves $audienceType to the actual
 * MessageThreadRecipient rows fanned out at send time); nothing about who can see it ever changes
 * afterwards.
 *
 * Whether a thread behaves as an ordinary back-and-forth (replies post into the same thread,
 * visible to both participants) or as a one-way announcement (any reply spins off a brand new
 * private thread with $sender instead of posting here) is NOT stored on $audienceType - it's
 * derived live from the actual resolved recipient count (see
 * App\Security\Voter\MessageThreadVoter::isAnnouncementShaped()). A Manual thread with exactly one
 * recipient is a plain 1:1 conversation; ProgramStudents/ProgramTeachers/SchoolWide almost always
 * resolve to more than one and so are announcement-shaped in practice, but it's the count that
 * decides it, not the type.
 *
 * Implements AudienceTargetable alongside App\Entity\Announcement/App\Entity\AgendaEvent - all
 * three share the same audience shape and are resolved by the same App\Service\AudienceResolver,
 * even though (unlike those two) a thread's resolved recipients are also fanned out into
 * persistent MessageThreadRecipient rows for read-tracking, which the other two don't need.
 */
#[ORM\Entity(repositoryClass: MessageThreadRepository::class)]
#[ORM\Table(name: 'message_thread')]
class MessageThread implements AudienceTargetable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $subject = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'sender_id', nullable: false)]
    private ?User $sender = null;

    #[ORM\Column(name: 'audience_type', length: 20, enumType: MessageAudienceType::class)]
    #[Assert\NotNull]
    private ?MessageAudienceType $audienceType = null;

    // Set only for ProgramStudents/ProgramTeachers - which Program the audience was resolved
    // against (App\Service\AudienceResolver).
    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: true)]
    private ?Program $program = null;

    // Populated only when $audienceType is Manual - cleared otherwise, same convention as
    // Assignment::$manualRecipients/$options.
    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'message_thread_manual_recipient')]
    private Collection $manualRecipients;

    // Set when this thread was spawned as a private reply to an announcement-shaped thread (see
    // class docblock) - purely for the recipient's/sender's own "in reply to" navigation context,
    // never used to derive permissions or audience.
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'in_reply_to_thread_id', nullable: true)]
    private ?self $inReplyToThread = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    // Denormalized copy of the latest Message's sentAt in this thread - updated on creation and on
    // every reply. Makes both inbox ordering and the unread-nav-badge query
    // (App\Twig\MessagingExtension) a cheap indexed comparison instead of a correlated subquery
    // against Message on every page load.
    #[ORM\Column(name: 'last_message_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastMessageAt;

    public function __construct(User $sender)
    {
        $this->sender = $sender;
        $this->manualRecipients = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->lastMessageAt = $this->createdAt;
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

    public function getSender(): ?User
    {
        return $this->sender;
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

    public function getInReplyToThread(): ?self
    {
        return $this->inReplyToThread;
    }

    public function setInReplyToThread(?self $inReplyToThread): static
    {
        $this->inReplyToThread = $inReplyToThread;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastMessageAt(): \DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function touchLastMessageAt(\DateTimeImmutable $sentAt): static
    {
        $this->lastMessageAt = $sentAt;

        return $this;
    }
}
