<?php

namespace App\Entity;

use App\Repository\AssignmentSubmissionFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One uploaded file within a student's AssignmentSubmission - see App\Service\FileUploadService. */
#[ORM\Entity(repositoryClass: AssignmentSubmissionFileRepository::class)]
#[ORM\Table(name: 'assignment_submission_file')]
class AssignmentSubmissionFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AssignmentSubmission::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'submission_id', nullable: false)]
    private ?AssignmentSubmission $submission = null;

    // S3 object key - not a URL, see App\Service\FileUploadService.
    #[ORM\Column(name: 'storage_key', length: 255)]
    private ?string $storageKey = null;

    // The filename as the student uploaded it - $storageKey is randomized to avoid collisions,
    // so this is what's actually shown/used as the download name.
    #[ORM\Column(name: 'original_filename', length: 255)]
    private ?string $originalFilename = null;

    #[ORM\Column(name: 'uploaded_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $uploadedAt = null;

    public function __construct(AssignmentSubmission $submission, string $storageKey, string $originalFilename)
    {
        $this->submission = $submission;
        $this->storageKey = $storageKey;
        $this->originalFilename = $originalFilename;
        $this->uploadedAt = new \DateTimeImmutable();

        if (!$submission->getFiles()->contains($this)) {
            $submission->getFiles()->add($this);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubmission(): ?AssignmentSubmission
    {
        return $this->submission;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function getUploadedAt(): ?\DateTimeImmutable
    {
        return $this->uploadedAt;
    }
}
