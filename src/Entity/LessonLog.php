<?php

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
     * Visibilité de chacun des trois temps (maquette 2a). Trois colonnes plutôt qu'une table :
     * il y en a exactement trois, connues à l'avance, et les lire ensemble est la règle - un
     * cahier de texte s'affiche toujours en entier.
     *
     * Le défaut est Hidden : un cahier de texte à peine ouvert n'est encore lisible de personne,
     * et c'est l'enseignant qui décide de publier temps par temps.
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

    // Accès champ par champ, pour que le formulaire puisse les mapper : getVisibility() et son
    // setter prennent un temps en argument, ce que Symfony ne sait pas appeler.
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

    // Accès champ par champ, pour que le formulaire puisse les mapper : getVisibility() et son
    // setter prennent un temps en argument, ce que Symfony ne sait pas appeler.
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

    // Accès champ par champ, pour que le formulaire puisse les mapper : getVisibility() et son
    // setter prennent un temps en argument, ce que Symfony ne sait pas appeler.
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
        // La date n'est conservée que par le choix qui en demande une : repasser en « visible dès
        // maintenant » puis revenir à « programmer » ne doit pas ressusciter une date oubliée.
        $visibleAt = $visibility->needsDate() ? $visibleAt : null;

        match ($section) {
            LessonLogSection::Before => [$this->visibilityBefore, $this->visibleAtBefore] = [$visibility, $visibleAt],
            LessonLogSection::During => [$this->visibilityDuring, $this->visibleAtDuring] = [$visibility, $visibleAt],
            LessonLogSection::After => [$this->visibilityAfter, $this->visibleAtAfter] = [$visibility, $visibleAt],
        };

        return $this;
    }

    /**
     * La date à laquelle un temps devient lisible, ou null s'il ne le devient jamais de lui-même.
     * « Fin de la séance » se lit sur le créneau, ce qui évite de recopier une date qui bougerait
     * si l'emploi du temps change.
     */
    public function getVisibleAt(LessonLogSection $section): ?\DateTimeImmutable
    {
        return match ($this->getVisibility($section)) {
            // Now n'a pas de date : isSectionVisible() le traite à part, et l'écran n'affiche
            // alors pas de « depuis le … » mais un simple « visible ».
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
