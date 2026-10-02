<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OnlineCourseMaterialKind;
use App\Repository\OnlineCourseMaterialRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One material of an online course: its interactive version, its PDF, its summary sheet, a video
 * (design/validated/cours-en-ligne.md, §4). A course usually carries one per nature, and nothing
 * forces it to: two videos are told apart by their label.
 *
 * The files are **revisions** (App\Entity\OnlineCourseMaterialRevision). Replacing a material
 * writes a new one in a folder of its own, so the public address of the material never changes
 * while the address of its bytes always does - the CDN can then never serve a mix of two versions.
 * `$liveRevision` names the one being served; the one before it is kept, so that going back is a
 * button, and anything older is handed to the deferred purge.
 *
 * `$slug` is the last segment of the material's own address (`…/les-jointures-sql/summary`) and is
 * unique within its course.
 */
#[ORM\Entity(repositoryClass: OnlineCourseMaterialRepository::class)]
#[ORM\Table(name: 'online_course_material')]
#[ORM\UniqueConstraint(name: 'uniq_online_course_material_slug', columns: ['course_id', 'slug'])]
class OnlineCourseMaterial
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OnlineCourse::class, inversedBy: 'materials')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OnlineCourse $course = null;

    #[ORM\Column(length: 20, enumType: OnlineCourseMaterialKind::class)]
    private OnlineCourseMaterialKind $kind;

    /** What its tab is called when the nature's own name is not enough (« Partie 2 »). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 60)]
    private string $slug;

    #[ORM\Column]
    private int $position = 0;

    /** The random folder its revisions are written under, inside the course's own. */
    #[ORM\Column(length: 16)]
    private string $storageSegment;

    /** The number the next revision takes - it only ever grows, so a folder name is never reused. */
    #[ORM\Column]
    private int $revisionCounter = 0;

    /** The number of the revision being served, 0 while there is none. */
    #[ORM\Column]
    private int $liveRevision = 0;

    /** @var Collection<int, OnlineCourseMaterialRevision> */
    #[ORM\OneToMany(mappedBy: 'material', targetEntity: OnlineCourseMaterialRevision::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'DESC'])]
    private Collection $revisions;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(OnlineCourse $course, OnlineCourseMaterialKind $kind, string $slug)
    {
        $this->course = $course;
        $this->kind = $kind;
        $this->slug = $slug;
        $this->storageSegment = bin2hex(random_bytes(8));
        $this->revisions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCourse(): OnlineCourse
    {
        \assert(null !== $this->course);

        return $this->course;
    }

    public function getKind(): OnlineCourseMaterialKind
    {
        return $this->kind;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $label = trim((string) $label);
        $this->label = '' === $label ? null : mb_substr($label, 0, 100);

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getStorageSegment(): string
    {
        return $this->storageSegment;
    }

    /** The folder a revision of this material is written under, ending with a slash. */
    public function storagePrefixFor(int $revisionNumber): string
    {
        return \sprintf('online-courses/%d/%s/%s/r%d/', (int) $this->getCourse()->getId(), $this->getCourse()->getStorageToken(), $this->storageSegment, $revisionNumber);
    }

    public function nextRevisionNumber(): int
    {
        return $this->revisionCounter + 1;
    }

    /**
     * Makes this revision the one being served. Its number must be the next one: the counter is
     * what guarantees a folder is never written twice.
     */
    public function publishRevision(OnlineCourseMaterialRevision $revision): static
    {
        if ($revision->getNumber() !== $this->nextRevisionNumber()) {
            throw new \LogicException(\sprintf('Revision %d is not the next one of material %d.', $revision->getNumber(), (int) $this->id));
        }

        $this->revisionCounter = $revision->getNumber();
        $this->revisions->add($revision);
        $this->liveRevision = $revision->getNumber();

        return $this;
    }

    public function getLiveRevisionNumber(): int
    {
        return $this->liveRevision;
    }

    public function getLive(): ?OnlineCourseMaterialRevision
    {
        foreach ($this->revisions as $revision) {
            if ($revision->getNumber() === $this->liveRevision) {
                return $revision;
            }
        }

        return null;
    }

    /** The revision kept beside the live one - what « Revenir à la révision précédente » serves again. */
    public function getOther(): ?OnlineCourseMaterialRevision
    {
        foreach ($this->revisions as $revision) {
            if ($revision->getNumber() !== $this->liveRevision) {
                return $revision;
            }
        }

        return null;
    }

    /** Swaps the live revision with the kept one; nothing is written or removed. */
    public function switchToOther(): bool
    {
        $other = $this->getOther();
        if (null === $other) {
            return false;
        }

        $this->liveRevision = $other->getNumber();

        return true;
    }

    /** @return Collection<int, OnlineCourseMaterialRevision> */
    public function getRevisions(): Collection
    {
        return $this->revisions;
    }

    public function removeRevision(OnlineCourseMaterialRevision $revision): static
    {
        $this->revisions->removeElement($revision);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
