<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TeacherDossierState;
use App\Repository\TeacherDossierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Dossier intervenant » — the pieces the administration asks of **one** teacher
 * (design/validated/dossiers-intervenants.md).
 *
 * The same idea as the dossier documentaire (App\Entity\Dossier), and deliberately not the same
 * table: there the people who follow a dossier are named one by one and the staff does not enter by
 * its role, here the administration enters by its role and nobody else does - and what is collected
 * is an identity card and a bank statement, which one forgotten filter on a teaching screen must
 * never be able to show.
 *
 * **A dossier with no teacher is a model.** Same table, same pieces, same editor: a model is the
 * composition somebody wrote, waiting to be copied for an intervenant
 * (App\Service\TeacherDossier\TeacherDossierComposer). The two named constructors are the only way
 * in, and the refusals that keep the two apart are here rather than in a controller: a model is
 * never published, never closed, and its pieces carry no date.
 *
 * Nothing here stores a status or an avancement - App\Service\Dossier\DossierStatusResolver reads
 * them from the dépôts, as it does for the dossiers documentaires.
 */
#[ORM\Entity(repositoryClass: TeacherDossierRepository::class)]
#[ORM\Table(name: 'teacher_dossier')]
#[ORM\Index(name: 'teacher_dossier_teacher_state_idx', columns: ['teacher_id', 'state'])]
class TeacherDossier
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Null on a model, and only there. A dossier's intervenant is fixed at creation: the pieces
    // handed in are theirs, and a dossier that changed hands would carry somebody else's papers.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: true)]
    private ?User $teacher = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    /** The « consignes » — one text for the whole dossier, shown to the intervenant. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: TeacherDossierState::class)]
    private TeacherDossierState $state = TeacherDossierState::Draft;

    // The model this dossier was copied from - kept to say so on screen and to count « n dossiers
    // créés depuis ce modèle », never to follow it: changing or deleting a model leaves the
    // dossiers made from it exactly as they are.
    //
    // Named « source » so that it cannot be mistaken, in a template above all, for isTemplate():
    // Twig reads `dossier.template` as either, depending on which method it finds first.
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'template_id', nullable: true, onDelete: 'SET NULL')]
    private ?TeacherDossier $sourceTemplate = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, TeacherDossierGroup> */
    #[ORM\OneToMany(mappedBy: 'dossier', targetEntity: TeacherDossierGroup::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $groups;

    /** @var Collection<int, TeacherDossierDocument> */
    #[ORM\OneToMany(mappedBy: 'dossier', targetEntity: TeacherDossierDocument::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $documents;

    private function __construct(User $author, string $title)
    {
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->groups = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->setCreatedBy($author);
    }

    /** A model: a composition with nobody to hand it to yet. */
    public static function asTemplate(User $author, string $title): self
    {
        return new self($author, $title);
    }

    public static function forTeacher(User $author, User $teacher, string $title): self
    {
        $dossier = new self($author, $title);
        $dossier->teacher = $teacher;

        return $dossier;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    public function isTemplate(): bool
    {
        return null === $this->teacher;
    }

    /**
     * Is this person the intervenant the dossier is asked of?
     *
     * Identity first, ids second, and never ids alone: two users not flushed yet both answer a null
     * id, and `null === null` would make everybody the holder of every dossier.
     */
    public function isHeldBy(User $user): bool
    {
        $teacher = $this->teacher;

        if (null === $teacher) {
            return false;
        }

        return $teacher === $user || (null !== $teacher->getId() && $teacher->getId() === $user->getId());
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getState(): TeacherDossierState
    {
        return $this->state;
    }

    public function isDraft(): bool
    {
        return TeacherDossierState::Draft === $this->state;
    }

    public function isPublished(): bool
    {
        return TeacherDossierState::Published === $this->state;
    }

    public function isClosed(): bool
    {
        return TeacherDossierState::Closed === $this->state;
    }

    /** Published or closed: what the intervenant can read. A draft and a model are not. */
    public function isReadableByTeacher(): bool
    {
        return !$this->isTemplate() && !$this->isDraft();
    }

    /**
     * Hands the dossier to its intervenant. Idempotent, and refused on a model - there is nobody to
     * hand a model to.
     *
     * @throws \LogicException on a model
     */
    public function publish(): static
    {
        if ($this->isTemplate()) {
            throw new \LogicException('A model is never published: it has no intervenant to be handed to.');
        }

        if ($this->isDraft()) {
            $this->state = TeacherDossierState::Published;
            $this->publishedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    /** Closes a published dossier. A draft has nothing to close: it is deleted, or published first. */
    public function close(): static
    {
        if ($this->isPublished()) {
            $this->state = TeacherDossierState::Closed;
            $this->closedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function reopen(): static
    {
        if ($this->isClosed()) {
            $this->state = TeacherDossierState::Published;
            $this->closedAt = null;
        }

        return $this;
    }

    public function getSourceTemplate(): ?self
    {
        return $this->sourceTemplate;
    }

    public function setSourceTemplate(?self $sourceTemplate): static
    {
        $this->sourceTemplate = $sourceTemplate;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    /** @return Collection<int, TeacherDossierGroup> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(TeacherDossierGroup $group): static
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
        }

        return $this;
    }

    /** @return Collection<int, TeacherDossierDocument> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(TeacherDossierDocument $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
        }

        return $this;
    }

    public function removeDocument(TeacherDossierDocument $document): static
    {
        $this->documents->removeElement($document);

        return $this;
    }

    /**
     * The pieces of one group, or - with null - the ones filed nowhere.
     *
     * Sorted here rather than trusted to #[ORM\OrderBy]: that only applies to a collection Doctrine
     * loaded, and this runs just as often on a dossier being composed in memory.
     *
     * @return list<TeacherDossierDocument>
     */
    public function documentsOfGroup(?TeacherDossierGroup $group): array
    {
        $documents = array_values(array_filter(
            $this->documents->toArray(),
            static fn (TeacherDossierDocument $document): bool => $document->getGroup() === $group,
        ));

        usort($documents, static fn (TeacherDossierDocument $a, TeacherDossierDocument $b): int => [$a->getPosition(), (int) $a->getId()] <=> [$b->getPosition(), (int) $b->getId()]);

        return $documents;
    }

    /**
     * The pieces filed under their group, the ungrouped ones last.
     *
     * A group with no piece is listed all the same - it is a title somebody wrote; an empty « hors
     * groupe », having no title of its own, is not.
     *
     * @return list<array{group: TeacherDossierGroup|null, documents: list<TeacherDossierDocument>}>
     */
    public function groupedDocuments(): array
    {
        $groups = [];

        foreach ($this->groups as $group) {
            $groups[] = ['group' => $group, 'documents' => $this->documentsOfGroup($group)];
        }

        $ungrouped = $this->documentsOfGroup(null);

        if ([] !== $ungrouped) {
            $groups[] = ['group' => null, 'documents' => $ungrouped];
        }

        return $groups;
    }

    /** How many pieces nobody may skip - the denominator of the avancement. */
    public function requiredDocumentCount(): int
    {
        return $this->documents->filter(static fn (TeacherDossierDocument $d): bool => $d->isRequired())->count();
    }

    public function nextDocumentPosition(): int
    {
        $positions = $this->documents->map(static fn (TeacherDossierDocument $d): int => $d->getPosition())->toArray();

        return [] === $positions ? 0 : max($positions) + 1;
    }

    public function nextGroupPosition(): int
    {
        $positions = $this->groups->map(static fn (TeacherDossierGroup $g): int => $g->getPosition())->toArray();

        return [] === $positions ? 0 : max($positions) + 1;
    }
}
