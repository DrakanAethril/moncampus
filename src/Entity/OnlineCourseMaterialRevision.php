<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OnlineCourseMaterialRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The bytes of a course material at one moment (design/validated/cours-en-ligne.md, §6): one file
 * for a PDF, a summary sheet or a video, a whole folder for an interactive course.
 *
 * `$storageKey` is the file's own key, or - for an interactive course - the key of its
 * `index.html`; either way it is what the CDN is asked for. `$storagePrefix` is the folder the
 * revision was written under, which is what the deferred purge is handed when the revision goes.
 */
#[ORM\Entity(repositoryClass: OnlineCourseMaterialRevisionRepository::class)]
#[ORM\Table(name: 'online_course_material_revision')]
#[ORM\UniqueConstraint(name: 'uniq_online_course_material_revision_number', columns: ['material_id', 'number'])]
class OnlineCourseMaterialRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OnlineCourseMaterial::class, inversedBy: 'revisions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OnlineCourseMaterial $material = null;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 255)]
    private string $storagePrefix;

    #[ORM\Column(length: 255)]
    private string $storageKey;

    /** The name the author's file had - a label on their screen, never part of a key. */
    #[ORM\Column(length: 255)]
    private string $originalName;

    /** In bytes; for an interactive course, the sum of its files. */
    #[ORM\Column]
    private int $fileSize;

    /** How many files an interactive course unpacked into; null for a single file. */
    #[ORM\Column(nullable: true)]
    private ?int $fileCount = null;

    /**
     * Every key of an interactive course, relative to the prefix - what the purge needs, since a
     * bucket has no folders to delete. Null for a single file.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $files = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string>|null $files
     */
    public function __construct(OnlineCourseMaterial $material, int $number, string $storagePrefix, string $storageKey, string $originalName, int $fileSize, ?array $files = null)
    {
        $this->material = $material;
        $this->number = $number;
        $this->storagePrefix = $storagePrefix;
        $this->storageKey = $storageKey;
        $this->originalName = mb_substr($originalName, 0, 255);
        $this->fileSize = $fileSize;
        $this->files = $files;
        $this->fileCount = null === $files ? null : \count($files);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMaterial(): OnlineCourseMaterial
    {
        \assert(null !== $this->material);

        return $this->material;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getStoragePrefix(): string
    {
        return $this->storagePrefix;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getFileCount(): ?int
    {
        return $this->fileCount;
    }

    /**
     * Every object this revision wrote, as full keys.
     *
     * @return list<string>
     */
    public function storedKeys(): array
    {
        if (null === $this->files) {
            return [$this->storageKey];
        }

        return array_map(fn (string $file): string => $this->storagePrefix.$file, $this->files);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
