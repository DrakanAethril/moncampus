<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeacherDossierSubmissionRepository;
use App\Service\Dossier\DepositVersion;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One dépôt on one piece of a dossier intervenant.
 *
 * **Versions are rows**, as on a dossier documentaire: redepositing after a correction writes
 * $version + 1 and the earlier file stays readable next to the comment that asked for another.
 * The dossier has one intervenant, so the piece and the version are the whole key - there is no
 * « whose dépôt » column to add to it.
 *
 * What there is instead is **$submittedBy**: the intervenant, or a member of the administration who
 * was handed the piece on paper or by mail and deposited it for them. The row says which, and the
 * screens print it.
 *
 * It holds either a file or a URL, never both, and carries no status: the last
 * App\Entity\TeacherDossierReview on the highest version is what decides.
 */
#[ORM\Entity(repositoryClass: TeacherDossierSubmissionRepository::class)]
#[ORM\Table(name: 'teacher_dossier_submission')]
#[ORM\UniqueConstraint(name: 'teacher_dossier_submission_version_unique', columns: ['document_id', 'version'])]
class TeacherDossierSubmission implements DepositVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TeacherDossierDocument::class)]
    #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'CASCADE')]
    private ?TeacherDossierDocument $document = null;

    #[ORM\Column]
    private int $version = 1;

    // Object key in the uploads bucket, under App\Service\TeacherDossier\TeacherDossierFiles' own
    // prefix. Never turned into a CDN address: see that service. Null on a `url` piece.
    #[ORM\Column(name: 'storage_key', length: 255, nullable: true)]
    private ?string $storageKey = null;

    #[ORM\Column(name: 'original_filename', length: 255, nullable: true)]
    private ?string $originalFilename = null;

    #[ORM\Column(name: 'byte_size', nullable: true)]
    private ?int $byteSize = null;

    // Null on an `upload` piece.
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'submitted_by_id', nullable: false)]
    private ?User $submittedBy = null;

    /** @var Collection<int, TeacherDossierReview> */
    #[ORM\OneToMany(mappedBy: 'submission', targetEntity: TeacherDossierReview::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $reviews;

    private function __construct(TeacherDossierDocument $document, User $submittedBy, int $version)
    {
        $this->document = $document;
        $this->submittedBy = $submittedBy;
        $this->version = $version;
        $this->submittedAt = new \DateTimeImmutable();
        $this->reviews = new ArrayCollection();
    }

    public static function ofFile(TeacherDossierDocument $document, User $submittedBy, int $version, string $storageKey, string $originalFilename, ?int $byteSize): self
    {
        $submission = new self($document, $submittedBy, $version);
        $submission->storageKey = $storageKey;
        $submission->originalFilename = $originalFilename;
        $submission->byteSize = $byteSize;

        return $submission;
    }

    public static function ofUrl(TeacherDossierDocument $document, User $submittedBy, int $version, string $url): self
    {
        $submission = new self($document, $submittedBy, $version);
        $submission->url = $url;

        return $submission;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): ?TeacherDossierDocument
    {
        return $this->document;
    }

    #[\Override]
    public function getVersion(): int
    {
        return $this->version;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function getByteSize(): ?int
    {
        return $this->byteSize;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    /** Was this handed in by somebody of the administration, on the intervenant's behalf? */
    public function isOnBehalf(): bool
    {
        $dossier = $this->document?->getDossier();

        return null !== $dossier && null !== $this->submittedBy && !$dossier->isHeldBy($this->submittedBy);
    }

    /** @return Collection<int, TeacherDossierReview> */
    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    public function addReview(TeacherDossierReview $review): static
    {
        if (!$this->reviews->contains($review)) {
            $this->reviews->add($review);
        }

        return $this;
    }

    #[\Override]
    public function lastReview(): ?TeacherDossierReview
    {
        return $this->reviews->last() ?: null;
    }

    /** The extension badge the screens draw (PDF, JPG…), or null on a URL dépôt. */
    public function extension(): ?string
    {
        if (null === $this->originalFilename) {
            return null;
        }

        $extension = pathinfo($this->originalFilename, \PATHINFO_EXTENSION);

        return '' === $extension ? null : strtoupper($extension);
    }
}
