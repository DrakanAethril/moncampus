<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DossierSubmissionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One dépôt of one cible on one document.
 *
 * **Versions are rows, not a column that gets overwritten.** Redepositing after a correction writes
 * a new row with $version + 1, and the previous one keeps its file, its date and the échanges it
 * carried - which is the only way the validateur's historique (« Dépôt v2 · Correction demandée ·
 * Dépôt v1 ») can be read back. The « Remplacé » tag on an older version is therefore a *reading* of
 * « a higher version exists », not a stored state.
 *
 * It holds **either** a file or a URL, decided by the document's own
 * App\Enum\DossierDepositType - never both, and the constructors below are what enforce it.
 *
 * It carries **no status column**. Whether this dépôt is validated, awaiting a validateur or to be
 * corrected is the last DossierReview on it, read by
 * App\Service\Dossier\DossierStatusResolver - storing it as well would give the screen two answers
 * that can drift.
 */
#[ORM\Entity(repositoryClass: DossierSubmissionRepository::class)]
#[ORM\Table(name: 'dossier_submission')]
#[ORM\UniqueConstraint(name: 'dossier_submission_version_unique', columns: ['document_id', 'student_id', 'version'])]
#[ORM\Index(name: 'dossier_submission_document_student_idx', columns: ['document_id', 'student_id'])]
class DossierSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DossierDocument::class)]
    #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'CASCADE')]
    private ?DossierDocument $document = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?User $student = null;

    #[ORM\Column]
    private int $version = 1;

    // S3 object key, never a URL - see App\Service\FileUploadService. Null on a `url` document.
    #[ORM\Column(name: 'storage_key', length: 255, nullable: true)]
    private ?string $storageKey = null;

    #[ORM\Column(name: 'original_filename', length: 255, nullable: true)]
    private ?string $originalFilename = null;

    #[ORM\Column(name: 'byte_size', nullable: true)]
    private ?int $byteSize = null;

    // Null on an `upload` document.
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    /** @var Collection<int, DossierReview> */
    #[ORM\OneToMany(mappedBy: 'submission', targetEntity: DossierReview::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $reviews;

    private function __construct(DossierDocument $document, User $student, int $version)
    {
        $this->document = $document;
        $this->student = $student;
        $this->version = $version;
        $this->submittedAt = new \DateTimeImmutable();
        $this->reviews = new ArrayCollection();
    }

    public static function ofFile(DossierDocument $document, User $student, int $version, string $storageKey, string $originalFilename, ?int $byteSize): self
    {
        $submission = new self($document, $student, $version);
        $submission->storageKey = $storageKey;
        $submission->originalFilename = $originalFilename;
        $submission->byteSize = $byteSize;

        return $submission;
    }

    public static function ofUrl(DossierDocument $document, User $student, int $version, string $url): self
    {
        $submission = new self($document, $student, $version);
        $submission->url = $url;

        return $submission;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): ?DossierDocument
    {
        return $this->document;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

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

    /** @return Collection<int, DossierReview> */
    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    public function addReview(DossierReview $review): static
    {
        if (!$this->reviews->contains($review)) {
            $this->reviews->add($review);
        }

        return $this;
    }

    /** The échange that decides this dépôt's status - the last one written, or none. */
    public function lastReview(): ?DossierReview
    {
        return $this->reviews->last() ?: null;
    }

    /** The extension badge the screens draw (PDF, DOCX…), or null on a URL dépôt. */
    public function extension(): ?string
    {
        if (null === $this->originalFilename) {
            return null;
        }

        $extension = pathinfo($this->originalFilename, \PATHINFO_EXTENSION);

        return '' === $extension ? null : strtoupper($extension);
    }
}
