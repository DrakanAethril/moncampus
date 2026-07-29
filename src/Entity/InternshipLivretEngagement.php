<?php

namespace App\Entity;

use App\Repository\InternshipLivretEngagementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The "mise à disposition du livret" gate for one InternshipTutorLink: tutor and student sign
 * their own acknowledgement in any order, and only once both are signed is the centre
 * representative's signature offered - which then opens the alternance's evaluation periods (see
 * AlternanceEngagementService). One row per InternshipTutorLink, lazily find-or-created the first
 * time any of its 3 signing routes is opened (same pattern as ContractType's singleton).
 */
#[ORM\Entity(repositoryClass: InternshipLivretEngagementRepository::class)]
#[ORM\Table(name: 'internship_livret_engagement')]
class InternshipLivretEngagement
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: InternshipTutorLink::class)]
    #[ORM\JoinColumn(name: 'tutor_link_id', nullable: false, unique: true)]
    private ?InternshipTutorLink $tutorLink = null;

    #[ORM\Column(name: 'signed_tutor_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $signedTutorAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'signed_tutor_by_id', nullable: true)]
    private ?User $signedTutorBy = null;

    #[ORM\Column(name: 'signed_student_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $signedStudentAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'signed_student_by_id', nullable: true)]
    private ?User $signedStudentBy = null;

    // Only settable once $signedTutorAt and $signedStudentAt are both non-null - enforced in
    // AlternanceEngagementService::signAsCenter(), not at the entity level.
    #[ORM\Column(name: 'signed_center_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $signedCenterAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'signed_center_by_id', nullable: true)]
    private ?User $signedCenterBy = null;

    public function __construct(InternshipTutorLink $tutorLink)
    {
        $this->tutorLink = $tutorLink;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTutorLink(): ?InternshipTutorLink
    {
        return $this->tutorLink;
    }

    public function getSignedTutorAt(): ?\DateTimeImmutable
    {
        return $this->signedTutorAt;
    }

    public function setSignedTutorAt(?\DateTimeImmutable $signedTutorAt): static
    {
        $this->signedTutorAt = $signedTutorAt;

        return $this;
    }

    public function getSignedTutorBy(): ?User
    {
        return $this->signedTutorBy;
    }

    public function setSignedTutorBy(?User $signedTutorBy): static
    {
        $this->signedTutorBy = $signedTutorBy;

        return $this;
    }

    public function getSignedStudentAt(): ?\DateTimeImmutable
    {
        return $this->signedStudentAt;
    }

    public function setSignedStudentAt(?\DateTimeImmutable $signedStudentAt): static
    {
        $this->signedStudentAt = $signedStudentAt;

        return $this;
    }

    public function getSignedStudentBy(): ?User
    {
        return $this->signedStudentBy;
    }

    public function setSignedStudentBy(?User $signedStudentBy): static
    {
        $this->signedStudentBy = $signedStudentBy;

        return $this;
    }

    public function getSignedCenterAt(): ?\DateTimeImmutable
    {
        return $this->signedCenterAt;
    }

    public function setSignedCenterAt(?\DateTimeImmutable $signedCenterAt): static
    {
        $this->signedCenterAt = $signedCenterAt;

        return $this;
    }

    public function getSignedCenterBy(): ?User
    {
        return $this->signedCenterBy;
    }

    public function setSignedCenterBy(?User $signedCenterBy): static
    {
        $this->signedCenterBy = $signedCenterBy;

        return $this;
    }

    public function isComplete(): bool
    {
        return null !== $this->signedCenterAt;
    }
}
