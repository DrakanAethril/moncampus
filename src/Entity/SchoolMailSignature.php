<?php

namespace App\Entity;

use App\Repository\SchoolMailSignatureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A student's own School mail signature (design_handoff_stage_alternance, screen 3f).
 *
 * A row only exists once the student has saved something: until then the school's default signature
 * is *computed* from what the platform already knows (civil status, programme, etu address, phone).
 * "Restore the default signature" deletes this row rather than rewriting it with the computed
 * values - that is what makes the default a live thing again, following its sources, instead of a
 * snapshot frozen on the day the screen was opened.
 *
 * Every field is nullable because an emptied one is stored as an empty string: that is how a student
 * drops a line they do not want to send (no phone number on a CV, for instance).
 */
#[ORM\Entity(repositoryClass: SchoolMailSignatureRepository::class)]
#[ORM\Table(name: 'school_mail_signature')]
#[ORM\UniqueConstraint(name: 'uniq_school_mail_signature_student', columns: ['student_id'])]
class SchoolMailSignature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'full_name', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $fullName = null;

    #[ORM\Column(name: 'program_label', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $programLabel = null;

    #[ORM\Column(name: 'email_address', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $emailAddress = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $phone = null;

    #[ORM\Column(name: 'linkedin_url', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $linkedinUrl = null;

    #[ORM\Column(name: 'github_url', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $githubUrl = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getProgramLabel(): ?string
    {
        return $this->programLabel;
    }

    public function setProgramLabel(?string $programLabel): static
    {
        $this->programLabel = $programLabel;

        return $this;
    }

    public function getEmailAddress(): ?string
    {
        return $this->emailAddress;
    }

    public function setEmailAddress(?string $emailAddress): static
    {
        $this->emailAddress = $emailAddress;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getLinkedinUrl(): ?string
    {
        return $this->linkedinUrl;
    }

    public function setLinkedinUrl(?string $linkedinUrl): static
    {
        $this->linkedinUrl = $linkedinUrl;

        return $this;
    }

    public function getGithubUrl(): ?string
    {
        return $this->githubUrl;
    }

    public function setGithubUrl(?string $githubUrl): static
    {
        $this->githubUrl = $githubUrl;

        return $this;
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
}
