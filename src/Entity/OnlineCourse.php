<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OnlineCourseStatus;
use App\Repository\OnlineCourseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A course a teacher puts online: a card (title, summary, description, tags, duration) and one or
 * several materials - an interactive course, a PDF, a summary sheet, a video
 * (design/validated/cours-en-ligne.md).
 *
 * Named OnlineCourse because « Mes cours » already means something else on this platform: the
 * course space of a class, made of its published séquences (App\Service\CourseSpaceBoard).
 *
 * It is its author's alone, administrators included (App\Security\Voter\OnlineCourseVoter); the one
 * thing an administrator does to somebody else's course is take it offline. Once published it is
 * read without an account, on its author's page - there is no catalogue across authors.
 *
 * `$slug` is the end of the public address. It is free while the course is a draft and frozen from
 * its first publication on ($publishedAt, set once and never cleared), so that a link handed to a
 * class survives a change of title.
 *
 * `$storageToken` is the random segment of every object the course stores - its picture included
 * (`online-courses/{id}/{token}/…`). Materials are served by the CDN, whose addresses are permanent
 * and unauthenticated: the token is what keeps a draft's files from being guessed.
 */
#[ORM\Entity(repositoryClass: OnlineCourseRepository::class)]
#[ORM\Table(name: 'online_course')]
#[ORM\UniqueConstraint(name: 'uniq_online_course_owner_slug', columns: ['owner_id', 'slug'])]
#[ORM\Index(name: 'idx_online_course_status', columns: ['status'])]
#[UniqueEntity(fields: ['owner', 'slug'], message: 'onlineCourseSlugTakenMessage', errorPath: 'slug')]
class OnlineCourse
{
    public const int DEFAULT_TEST_PASS_PERCENT = 80;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'onlineCourseTitleRequiredMessage')]
    #[Assert\Length(max: 200)]
    private string $title;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'onlineCourseSlugRequiredMessage')]
    #[Assert\Length(max: 120)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'onlineCourseSlugFormatMessage')]
    private string $slug;

    /** The sentence a course card shows, and the page's description for a search engine. */
    #[ORM\Column(length: 300)]
    #[Assert\Length(max: 300)]
    private string $summary = '';

    /** Sanitized HTML. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** In minutes, like a séance - never hours. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 6000)]
    private ?int $estimatedMinutes = null;

    #[ORM\Column(length: 20, enumType: OnlineCourseStatus::class)]
    private OnlineCourseStatus $status = OnlineCourseStatus::Draft;

    /** The first publication. Never cleared: it is what freezes the slug. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 32)]
    private string $storageToken;

    /**
     * The course's picture, on its card and in a link's preview: a key under the course's own
     * folder, served by the CDN like its materials. Written by
     * App\Service\OnlineCourse\OnlineCourseImageStore alone, which copies a library file rather
     * than referencing it - for the same reason as a material.
     */
    #[ORM\Column(name: 'image_key', length: 255, nullable: true)]
    private ?string $imageKey = null;

    /**
     * The quiz a reader may test themselves on, « Test » on the course's card: a quiz of the
     * author's own library, taken as many times as wanted and recorded nowhere
     * (App\Service\OnlineCourse\OnlineCourseTestRunner). Written by
     * App\Service\OnlineCourse\OnlineCourseWriter::linkQuiz() alone, which holds the rule on it.
     * A quiz deleted from the library leaves the course without a test, never without a course.
     */
    #[ORM\ManyToOne(targetEntity: QuizTemplate::class)]
    #[ORM\JoinColumn(name: 'quiz_template_id', nullable: true, onDelete: 'SET NULL')]
    private ?QuizTemplate $quizTemplate = null;

    /**
     * The share of right answers that earns « Bravo » at the end of the test; below it, the reader
     * is sent back to the course. Set when the quiz is linked; kept when it is unlinked, so that
     * linking another one does not silently fall back to the default.
     */
    #[ORM\Column(name: 'test_pass_percent')]
    private int $testPassPercent = self::DEFAULT_TEST_PASS_PERCENT;

    /** @var Collection<int, OnlineCourseTag> */
    #[ORM\ManyToMany(targetEntity: OnlineCourseTag::class)]
    #[ORM\JoinTable(name: 'online_course_tag_link')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['label' => 'ASC'])]
    private Collection $tags;

    /** @var Collection<int, OnlineCourseMaterial> */
    #[ORM\OneToMany(mappedBy: 'course', targetEntity: OnlineCourseMaterial::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $materials;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $title, string $slug)
    {
        $this->owner = $owner;
        $this->title = $title;
        $this->slug = $slug;
        $this->storageToken = bin2hex(random_bytes(16));
        $this->tags = new ArrayCollection();
        $this->materials = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        \assert(null !== $this->owner);

        return $this->owner;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner === $user;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Ignored once the course has been published: the address a class was given must keep
     * answering, and a form field that was disabled on screen can still be posted by hand.
     */
    public function setSlug(string $slug): static
    {
        if (!$this->isSlugFrozen()) {
            $this->slug = $slug;
        }

        return $this;
    }

    public function isSlugFrozen(): bool
    {
        return null !== $this->publishedAt;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = trim((string) $summary);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = null === $description || '' === trim($description) ? null : $description;

        return $this;
    }

    public function getEstimatedMinutes(): ?int
    {
        return $this->estimatedMinutes;
    }

    public function setEstimatedMinutes(?int $estimatedMinutes): static
    {
        $this->estimatedMinutes = $estimatedMinutes;

        return $this;
    }

    public function getStatus(): OnlineCourseStatus
    {
        return $this->status;
    }

    /** Goes through App\Service\OnlineCourse\OnlineCourseWriter, which holds what publishing asks for. */
    public function setStatus(OnlineCourseStatus $status): static
    {
        $this->status = $status;
        if ($status->isPublished() && null === $this->publishedAt) {
            $this->publishedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->status->isPublished();
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getStorageToken(): string
    {
        return $this->storageToken;
    }

    public function getImageKey(): ?string
    {
        return $this->imageKey;
    }

    public function setImageKey(?string $imageKey): static
    {
        $this->imageKey = $imageKey;

        return $this;
    }

    public function getQuizTemplate(): ?QuizTemplate
    {
        return $this->quizTemplate;
    }

    public function setQuizTemplate(?QuizTemplate $quizTemplate): static
    {
        $this->quizTemplate = $quizTemplate;

        return $this;
    }

    public function getTestPassPercent(): int
    {
        return $this->testPassPercent;
    }

    public function setTestPassPercent(int $testPassPercent): static
    {
        $this->testPassPercent = max(1, min(100, $testPassPercent));

        return $this;
    }

    /**
     * Whether « Test » is offered: a quiz is linked and still has a question. A quiz emptied in the
     * library since it was linked is a link to nothing, not a test with no question.
     */
    public function hasTest(): bool
    {
        return null !== $this->quizTemplate && !$this->quizTemplate->getQuestions()->isEmpty();
    }

    /** @return Collection<int, OnlineCourseTag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(OnlineCourseTag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(OnlineCourseTag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    public function hasTag(string $normalizedLabel): bool
    {
        foreach ($this->tags as $tag) {
            if ($tag->getNormalizedLabel() === $normalizedLabel) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, OnlineCourseMaterial> */
    public function getMaterials(): Collection
    {
        return $this->materials;
    }

    public function addMaterial(OnlineCourseMaterial $material): static
    {
        if (!$this->materials->contains($material)) {
            $this->materials->add($material);
        }

        return $this;
    }

    public function removeMaterial(OnlineCourseMaterial $material): static
    {
        $this->materials->removeElement($material);

        return $this;
    }

    public function findMaterialBySlug(string $slug): ?OnlineCourseMaterial
    {
        foreach ($this->materials as $material) {
            if ($material->getSlug() === $slug) {
                return $material;
            }
        }

        return null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Called by the writers: « Mis à jour le » on the public page is about the course, files included. */
    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
