<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VideoResourceFileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One video of a VideoResource.
 *
 * Storage keys only: like every upload in this app the object itself lives in S3 and is reached
 * through a signed address built at play time, never laid into the page. That matters more here
 * than for audio - a video is ten to a hundred times heavier, so handing its address to a page that
 * may never be played is bandwidth given away.
 */
#[ORM\Entity(repositoryClass: VideoResourceFileRepository::class)]
#[ORM\Table(name: 'video_resource_file')]
class VideoResourceFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The library file this row was linked from, when it was one
     * (design/validated/file-library.md, "The link, on nine existing tables").
     *
     * **The row keeps its own storage key**, copied from the node. That is the decision that makes
     * the whole feature cheap: every reader - a Twig template, file_url(), the mobile API, the PDF
     * exports, the mail attachment builder - is untouched, because nothing about *reading* a file
     * changes. This foreign key exists for one purpose: to answer "where is this file used", with a
     * real index and a real constraint rather than a polymorphic (target_type, target_id) table
     * Doctrine could not key - and which nothing would clean up when a host disappears.
     *
     * `SET NULL` and never `CASCADE`: removing the links is App\Service\FileLibraryLinks's job, done
     * deliberately when the teacher confirms « Supprimer partout ». A cascade here would delete the
     * *host* row - the quiz question, the séquence resource, the video and its statistics - as a
     * database side effect nobody can see.
     */
    #[ORM\ManyToOne(targetEntity: FileLibraryNode::class)]
    #[ORM\JoinColumn(name: 'library_node_id', nullable: true, onDelete: 'SET NULL')]
    private ?FileLibraryNode $libraryNode = null;

    #[ORM\ManyToOne(targetEntity: VideoResource::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'resource_id', nullable: false, onDelete: 'CASCADE')]
    private ?VideoResource $resource = null;

    #[ORM\Column(name: 'storage_key', length: 255)]
    private string $storageKey;

    // The still shown before playback. Optional: without one the player shows its own first frame,
    // which is worse-looking but never broken.
    #[ORM\Column(name: 'poster_storage_key', length: 255, nullable: true)]
    private ?string $posterStorageKey = null;

    #[ORM\Column(name: 'duration_seconds')]
    private int $durationSeconds = 0;

    #[ORM\Column(name: 'file_size')]
    private int $fileSize = 0;

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName = '';

    #[ORM\Column]
    private int $position = 0;

    /**
     * The questions embedded in this file (créas 5B). Cascaded and orphan-removed: a marker is part
     * of the file the way a page is part of a document - deleting the video takes them with it, and
     * the questions themselves stay in the library, untouched.
     *
     * @var Collection<int, VideoCuePoint>
     */
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: VideoCuePoint::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['timecodeSeconds' => 'ASC'])]
    private Collection $cuePoints;

    #[ORM\Column(name: 'uploaded_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    public function __construct(string $storageKey, int $position)
    {
        $this->storageKey = $storageKey;
        $this->position = $position;
        $this->uploadedAt = new \DateTimeImmutable();
        $this->cuePoints = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResource(): ?VideoResource
    {
        return $this->resource;
    }

    public function setResource(?VideoResource $resource): static
    {
        $this->resource = $resource;

        return $this;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    /**
     * The key changes exactly once in a file's life, and only for the deferred fork: a library file
     * this video was built on is being deleted, so App\Service\FileLibraryVideoFork copies the object
     * to a key the work owns and points the row at it (design/validated/file-library.md).
     *
     * It stays **non-null** through that, which is the point: there is no "media deleted" state to
     * build in the player, the statistics screen or the mobile API, where `Api\WorkController` builds
     * `'url' => playbackUrl($file->getStorageKey())` and must not answer null to a Flutter client.
     */
    public function setStorageKey(string $storageKey): static
    {
        $this->storageKey = $storageKey;

        return $this;
    }

    public function getPosterStorageKey(): ?string
    {
        return $this->posterStorageKey;
    }

    public function setPosterStorageKey(?string $posterStorageKey): static
    {
        $this->posterStorageKey = $posterStorageKey;

        return $this;
    }

    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(int $durationSeconds): static
    {
        $this->durationSeconds = max(0, $durationSeconds);

        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): static
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    /** @return Collection<int, VideoCuePoint> */
    public function getCuePoints(): Collection
    {
        return $this->cuePoints;
    }

    public function addCuePoint(VideoCuePoint $cuePoint): static
    {
        if (!$this->cuePoints->contains($cuePoint)) {
            $this->cuePoints->add($cuePoint);
        }

        return $this;
    }

    public function removeCuePoint(VideoCuePoint $cuePoint): static
    {
        $this->cuePoints->removeElement($cuePoint);

        return $this;
    }

    /** "12:40" - the shape every screen shows a running time in. */
    public function getFormattedDuration(): string
    {
        $minutes = intdiv($this->durationSeconds, 60);
        $seconds = $this->durationSeconds % 60;

        return \sprintf('%d:%02d', $minutes, $seconds);
    }

    public function getLibraryNode(): ?FileLibraryNode
    {
        return $this->libraryNode;
    }

    public function setLibraryNode(?FileLibraryNode $libraryNode): self
    {
        $this->libraryNode = $libraryNode;

        return $this;
    }
}
