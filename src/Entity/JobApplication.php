<?php

namespace App\Entity;

use App\Enum\JobApplicationOrigin;
use App\Repository\JobApplicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One student's application to one company (design_handoff_stage_alternance, screens 2a and 2b:
 * "Applications", grouped by company).
 *
 * Grouping happens per company and not per mail: a send, its follow-up and the reply received all
 * belong to the same application. The application carries the context (position, contact); the
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
#[ORM\Index(name: 'idx_job_application_enterprise', columns: ['enterprise_id'])]
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

    #[ORM\ManyToOne(targetEntity: Enterprise::class)]
    #[ORM\JoinColumn(name: 'enterprise_id', nullable: false)]
    #[Assert\NotNull]
    private ?Enterprise $enterprise = null;

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

    public function getEnterprise(): ?Enterprise
    {
        return $this->enterprise;
    }

    public function setEnterprise(?Enterprise $enterprise): static
    {
        $this->enterprise = $enterprise;

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
