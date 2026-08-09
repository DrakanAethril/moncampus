<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrainingApplicationVersionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One submission of a training application: the first send, then one per resend
 * (design_handoff_workflow_postulation, screens 8b and 8e).
 *
 * Versions are kept whole rather than overwritten, because the review is a conversation: screen 8d
 * offers "compare with v1", and a validator who asked for a shorter paragraph needs to see what
 * actually changed. The signature is stored as a snapshot for the same reason - the student may
 * edit theirs afterwards, and what was validated must stay readable as it was validated.
 */
#[ORM\Entity(repositoryClass: TrainingApplicationVersionRepository::class)]
#[ORM\Table(name: 'training_application_version')]
#[ORM\UniqueConstraint(name: 'uniq_training_application_version', columns: ['application_id', 'number'])]
class TrainingApplicationVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrainingApplication::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(name: 'application_id', nullable: false, onDelete: 'CASCADE')]
    private ?TrainingApplication $application = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $number = 1;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    /** The signature as it read on the day, not a pointer to one that can change afterwards. */
    #[ORM\Column(name: 'signature_snapshot', type: Types::TEXT, nullable: true)]
    private ?string $signatureSnapshot = null;

    /**
     * @var Collection<int, TrainingApplicationAttachment>
     *
     * Whatever the student joined, in the order they joined it - no CV slot, no cover-letter slot
     * (design_handoff_postulation_redaction). A version carries its own list rather than pointing at
     * the previous one's, so what a validator read stays exactly what they read.
     */
    #[ORM\OneToMany(mappedBy: 'version', targetEntity: TrainingApplicationAttachment::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $attachments;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    public function __construct()
    {
        $this->submittedAt = new \DateTimeImmutable();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApplication(): ?TrainingApplication
    {
        return $this->application;
    }

    public function setApplication(?TrainingApplication $application): static
    {
        $this->application = $application;

        return $this;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function setNumber(int $number): static
    {
        $this->number = $number;

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

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getSignatureSnapshot(): ?string
    {
        return $this->signatureSnapshot;
    }

    public function setSignatureSnapshot(?string $signatureSnapshot): static
    {
        $this->signatureSnapshot = $signatureSnapshot;

        return $this;
    }

    /** @return Collection<int, TrainingApplicationAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(TrainingApplicationAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $attachment->setPosition($this->attachments->count());
            $this->attachments->add($attachment);
            $attachment->setVersion($this);
        }

        return $this;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }
}
