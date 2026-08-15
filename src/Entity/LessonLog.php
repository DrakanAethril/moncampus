<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LessonLogSection;
use App\Enum\LessonLogVisibility;
use App\Repository\LessonLogRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The "cahier de texte" administrative record for one LessonSession: what was actually covered,
 * plus optional before/after work descriptions and attachments. Created lazily on first save - a
 * LessonSession with no LessonLog row yet simply has nothing filled in, not an error state (see
 * design/validated/lesson-log-cahier-de-texte.md - "filling is never automatic").
 */
#[ORM\Entity(repositoryClass: LessonLogRepository::class)]
#[ORM\Table(name: 'lesson_log')]
class LessonLog
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: LessonSession::class)]
    #[ORM\JoinColumn(name: 'lesson_session_id', nullable: false, unique: true)]
    private ?LessonSession $lessonSession = null;

    #[ORM\Column(name: 'contenu_realise', type: Types::TEXT, nullable: true)]
    private ?string $contenuRealise = null;

    #[ORM\Column(name: 'travail_avant_description', type: Types::TEXT, nullable: true)]
    private ?string $travailAvantDescription = null;

    #[ORM\Column(name: 'travail_apres_description', type: Types::TEXT, nullable: true)]
    private ?string $travailApresDescription = null;

    /** @var Collection<int, LessonLogAttachment> */
    #[ORM\OneToMany(targetEntity: LessonLogAttachment::class, mappedBy: 'lessonLog', orphanRemoval: true)]
    private Collection $attachments;

    /**
     * Visibility of each of the three parts (mockup 2a). Three columns rather than a table: there
     * are exactly three of them, known in advance, and reading them together is the rule - a cahier
     * de texte is always displayed whole.
     *
     * The default is Hidden: a cahier de texte barely opened is not yet readable by anyone, and it
     * is the teacher who decides to publish part by part.
     */
    #[ORM\Column(name: 'visibility_before', length: 20, enumType: LessonLogVisibility::class)]
    private LessonLogVisibility $visibilityBefore = LessonLogVisibility::Hidden;

    #[ORM\Column(name: 'visibility_during', length: 20, enumType: LessonLogVisibility::class)]
    private LessonLogVisibility $visibilityDuring = LessonLogVisibility::Hidden;

    #[ORM\Column(name: 'visibility_after', length: 20, enumType: LessonLogVisibility::class)]
    private LessonLogVisibility $visibilityAfter = LessonLogVisibility::Hidden;

    #[ORM\Column(name: 'visible_at_before', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $visibleAtBefore = null;

    #[ORM\Column(name: 'visible_at_during', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $visibleAtDuring = null;

    #[ORM\Column(name: 'visible_at_after', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $visibleAtAfter = null;

    public function __construct(LessonSession $lessonSession)
    {
        $this->attachments = new ArrayCollection();
        $this->lessonSession = $lessonSession;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLessonSession(): ?LessonSession
    {
        return $this->lessonSession;
    }

    public function getContenuRealise(): ?string
    {
        return $this->contenuRealise;
    }

    public function setContenuRealise(?string $contenuRealise): static
    {
        $this->contenuRealise = $contenuRealise;

        return $this;
    }

    public function getTravailAvantDescription(): ?string
    {
        return $this->travailAvantDescription;
    }

    public function setTravailAvantDescription(?string $travailAvantDescription): static
    {
        $this->travailAvantDescription = $travailAvantDescription;

        return $this;
    }

    public function getTravailApresDescription(): ?string
    {
        return $this->travailApresDescription;
    }

    public function setTravailApresDescription(?string $travailApresDescription): static
    {
        $this->travailApresDescription = $travailApresDescription;

        return $this;
    }

    /** @return Collection<int, LessonLogAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    /** @return Collection<int, LessonLogAttachment> */
    public function getAttachmentsForSection(LessonLogSection $section): Collection
    {
        return $this->attachments->filter(static fn (LessonLogAttachment $a): bool => $a->getSection() === $section);
    }

    public function getContent(LessonLogSection $section): ?string
    {
        return match ($section) {
            LessonLogSection::Before => $this->travailAvantDescription,
            LessonLogSection::During => $this->contenuRealise,
            LessonLogSection::After => $this->travailApresDescription,
        };
    }

    // Field-by-field accessors, so the form can map them: getVisibility() and its setter take a part
    // as an argument, which Symfony does not know how to call.
    public function getVisibilityBefore(): LessonLogVisibility
    {
        return $this->visibilityBefore;
    }

    public function setVisibilityBefore(LessonLogVisibility $visibility): static
    {
        $this->visibilityBefore = $visibility;

        return $this;
    }

    public function getVisibleAtBefore(): ?\DateTimeImmutable
    {
        return $this->visibleAtBefore;
    }

    public function setVisibleAtBefore(?\DateTimeImmutable $visibleAt): static
    {
        $this->visibleAtBefore = $visibleAt;

        return $this;
    }

    // Field-by-field accessors, so the form can map them: getVisibility() and its setter take a part
    // as an argument, which Symfony does not know how to call.
    public function getVisibilityDuring(): LessonLogVisibility
    {
        return $this->visibilityDuring;
    }

    public function setVisibilityDuring(LessonLogVisibility $visibility): static
    {
        $this->visibilityDuring = $visibility;

        return $this;
    }

    public function getVisibleAtDuring(): ?\DateTimeImmutable
    {
        return $this->visibleAtDuring;
    }

    public function setVisibleAtDuring(?\DateTimeImmutable $visibleAt): static
    {
        $this->visibleAtDuring = $visibleAt;

        return $this;
    }

    // Field-by-field accessors, so the form can map them: getVisibility() and its setter take a part
    // as an argument, which Symfony does not know how to call.
    public function getVisibilityAfter(): LessonLogVisibility
    {
        return $this->visibilityAfter;
    }

    public function setVisibilityAfter(LessonLogVisibility $visibility): static
    {
        $this->visibilityAfter = $visibility;

        return $this;
    }

    public function getVisibleAtAfter(): ?\DateTimeImmutable
    {
        return $this->visibleAtAfter;
    }

    public function setVisibleAtAfter(?\DateTimeImmutable $visibleAt): static
    {
        $this->visibleAtAfter = $visibleAt;

        return $this;
    }

    public function getVisibility(LessonLogSection $section): LessonLogVisibility
    {
        return match ($section) {
            LessonLogSection::Before => $this->visibilityBefore,
            LessonLogSection::During => $this->visibilityDuring,
            LessonLogSection::After => $this->visibilityAfter,
        };
    }

    public function setVisibility(LessonLogSection $section, LessonLogVisibility $visibility, ?\DateTimeImmutable $visibleAt = null): static
    {
        // The date is only kept by the choice that asks for one: switching back to « visible dès
        // maintenant » then returning to « programmer » must not resurrect a forgotten date.
        $visibleAt = $visibility->needsDate() ? $visibleAt : null;

        match ($section) {
            LessonLogSection::Before => [$this->visibilityBefore, $this->visibleAtBefore] = [$visibility, $visibleAt],
            LessonLogSection::During => [$this->visibilityDuring, $this->visibleAtDuring] = [$visibility, $visibleAt],
            LessonLogSection::After => [$this->visibilityAfter, $this->visibleAtAfter] = [$visibility, $visibleAt],
        };

        return $this;
    }

    /**
     * The date a part becomes readable, or null if it never becomes so by itself. « Fin de la
     * séance » is read off the slot, which avoids copying a date that would move if the timetable
     * changes.
     */
    public function getVisibleAt(LessonLogSection $section): ?\DateTimeImmutable
    {
        return match ($this->getVisibility($section)) {
            // Now has no date: isSectionVisible() handles it separately, and the screen then shows
            // no « depuis le … » but a plain « visible ».
            LessonLogVisibility::Now => null,
            LessonLogVisibility::AfterSession => $this->lessonSession?->getEndAt(),
            LessonLogVisibility::Scheduled => match ($section) {
                LessonLogSection::Before => $this->visibleAtBefore,
                LessonLogSection::During => $this->visibleAtDuring,
                LessonLogSection::After => $this->visibleAtAfter,
            },
            LessonLogVisibility::Hidden => null,
        };
    }

    public function isSectionVisible(LessonLogSection $section, ?\DateTimeImmutable $now = null): bool
    {
        if (LessonLogVisibility::Now === $this->getVisibility($section)) {
            return true;
        }

        $visibleAt = $this->getVisibleAt($section);

        return null !== $visibleAt && $visibleAt <= ($now ?? new \DateTimeImmutable());
    }
}
