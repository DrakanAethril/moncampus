<?php

namespace App\Entity;

use App\Repository\SchoolMailDraftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A message a student started writing but has not sent (design_handoff_stage_alternance, screens 3b
 * and 3d: the "Drafts" folder and the "Draft saved" note in the compose header).
 *
 * A table of its own rather than a state on App\Entity\EmailMessage, and that is the point: an
 * EmailMessage is the trace of a mail that really travelled - it has a Message-ID, an `.eml` on S3
 * and a delivery status. A draft has none of that and may never have any. Mixing them would have
 * meant nullable columns everywhere and a permanent risk of a draft leaking into a screen that
 * counts real mails.
 *
 * The company is not resolved here: linking happens at send time (screen 3g), on the address as it
 * stands at that moment. A draft therefore only ever holds text - and never attachments, which a
 * browser cannot restore into a file field anyway.
 */
#[ORM\Entity(repositoryClass: SchoolMailDraftRepository::class)]
#[ORM\Table(name: 'school_mail_draft')]
#[ORM\Index(name: 'idx_school_mail_draft_student', columns: ['student_id'])]
class SchoolMailDraft
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'recipient', length: 255, nullable: true)]
    private ?string $recipient = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    /**
     * The inbound mail this draft answers, when it was started from "Reply". Kept so that sending
     * still carries In-Reply-To/References, which is what puts the answer back into the right
     * application.
     */
    #[ORM\ManyToOne(targetEntity: EmailMessage::class)]
    #[ORM\JoinColumn(name: 'reply_to_id', nullable: true, onDelete: 'SET NULL')]
    private ?EmailMessage $replyTo = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getRecipient(): ?string
    {
        return $this->recipient;
    }

    public function setRecipient(?string $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getReplyTo(): ?EmailMessage
    {
        return $this->replyTo;
    }

    public function setReplyTo(?EmailMessage $replyTo): static
    {
        $this->replyTo = $replyTo;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** An empty draft is worth no row: autosave deletes it rather than keeping a blank line. */
    public function isEmpty(): bool
    {
        return '' === trim(($this->recipient ?? '').($this->subject ?? '').($this->body ?? ''));
    }
}
