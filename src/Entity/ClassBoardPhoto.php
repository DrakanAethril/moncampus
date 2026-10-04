<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClassBoardPhotoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The photograph of the day behind every virtual board on « Photo » (design/validated/tableau-virtuel.md §3).
 *
 * One row per day, written by `app:class-board:photo` - never during a request: the photograph is
 * picked on Wikimedia Commons, downloaded once and stored with the other uploads, so a board opening
 * in a classroom asks nothing of a third party. The row keeps what the licence asks to show (author,
 * licence, source page) for as long as the bytes are shown.
 *
 * The bytes live a couple of days and are then deleted (`storageKey` goes null); the row stays, so
 * the same photograph is not served again before the pool has been run through.
 */
#[ORM\Entity(repositoryClass: ClassBoardPhotoRepository::class)]
#[ORM\Table(name: 'class_board_photo')]
#[ORM\UniqueConstraint(name: 'uniq_class_board_photo_day', columns: ['day'])]
#[ORM\Index(name: 'class_board_photo_title_idx', columns: ['commons_title'])]
class ClassBoardPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The day it is shown, Paris time. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    /** `File:…` on Wikimedia Commons - what « already shown » is decided on. */
    #[ORM\Column(name: 'commons_title', length: 255)]
    private string $commonsTitle;

    /** Null once the bytes have been cleaned away. */
    #[ORM\Column(name: 'storage_key', length: 255, nullable: true)]
    private ?string $storageKey = null;

    /** Plain text, tags stripped from Commons' own HTML. */
    #[ORM\Column(length: 255)]
    private string $author;

    #[ORM\Column(name: 'license_name', length: 64)]
    private string $licenseName;

    #[ORM\Column(name: 'license_url', length: 255, nullable: true)]
    private ?string $licenseUrl = null;

    /** The file's page on Commons - where the full attribution and the original are. */
    #[ORM\Column(name: 'source_url', length: 255)]
    private string $sourceUrl;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(\DateTimeImmutable $day, string $commonsTitle, string $storageKey, string $author, string $licenseName, ?string $licenseUrl, string $sourceUrl)
    {
        $this->day = $day->setTime(0, 0);
        $this->commonsTitle = mb_substr($commonsTitle, 0, 255);
        $this->storageKey = $storageKey;
        $this->author = mb_substr($author, 0, 255);
        $this->licenseName = mb_substr($licenseName, 0, 64);
        $this->licenseUrl = null === $licenseUrl ? null : mb_substr($licenseUrl, 0, 255);
        $this->sourceUrl = mb_substr($sourceUrl, 0, 255);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getCommonsTitle(): string
    {
        return $this->commonsTitle;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function forgetBytes(): void
    {
        $this->storageKey = null;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getLicenseName(): string
    {
        return $this->licenseName;
    }

    public function getLicenseUrl(): ?string
    {
        return $this->licenseUrl;
    }

    public function getSourceUrl(): string
    {
        return $this->sourceUrl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
