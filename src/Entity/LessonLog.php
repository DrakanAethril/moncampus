<?php

namespace App\Entity;

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
}
