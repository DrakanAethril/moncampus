<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DossierState;
use App\Repository\DossierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Dossier documentaire » — a set of pieces to collect from a group of people.
 *
 * Three relations carry the whole feature and none of them is interchangeable:
 *
 * - **validateurs**, who follow the dossier and treat the dépôts. The créateur is one of them by
 *   construction (addValidator() is called on them at creation and removeValidator() refuses to take
 *   them back out): a dossier nobody can validate is a dossier nobody can finish.
 * - **cibles**, the people the pieces are asked of. Two axes, deliberately kept apart rather than
 *   merged into one polymorphic table: whole formations, and named students. The resolved audience
 *   is their union, recomputed at read time by App\Service\Dossier\DossierTargetResolver — a student
 *   who joins the class after publication is a cible the moment they join, exactly like an
 *   Assignment's audience.
 * - **documents**, optionally filed in groups. The group is a title and an order, nothing else;
 *   a document with no group is drawn under « Hors groupe » and is not a special case anywhere in
 *   the code.
 *
 * Nothing here stores a status or an avancement. Both are read from the dépôts and the calendar by
 * App\Service\Dossier\DossierStatusResolver, so a screen cannot disagree with the dates a document
 * carries.
 */
#[ORM\Entity(repositoryClass: DossierRepository::class)]
#[ORM\Table(name: 'dossier')]
class Dossier
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    /** The « consignes générales » — one text for the whole dossier, shown to the cible. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsOn = null;

    #[ORM\Column(name: 'ends_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    // AuditableTrait carries who created the row but not when - so the date the « Créé le 28 août »
    // line reads is this one, stamped at construction rather than by a listener.
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 20, enumType: DossierState::class)]
    private DossierState $state = DossierState::Draft;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /**
     * The people who follow the dossier and treat the dépôts — the créateur included.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'dossier_validator')]
    private Collection $validators;

    /**
     * The formations asked, each with the options it is narrowed to - see
     * App\Entity\DossierTargetProgram, which exists because the narrowing belongs to the pair and
     * not to the dossier.
     *
     * @var Collection<int, DossierTargetProgram>
     */
    #[ORM\OneToMany(mappedBy: 'dossier', targetEntity: DossierTargetProgram::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $targetPrograms;

    /**
     * Students named one by one, on top of (or instead of) the formations above.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'dossier_target_student')]
    private Collection $targetStudents;

    /** @var Collection<int, DossierGroup> */
    #[ORM\OneToMany(mappedBy: 'dossier', targetEntity: DossierGroup::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $groups;

    /** @var Collection<int, DossierDocument> */
    #[ORM\OneToMany(mappedBy: 'dossier', targetEntity: DossierDocument::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $documents;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->validators = new ArrayCollection();
        $this->targetPrograms = new ArrayCollection();
        $this->targetStudents = new ArrayCollection();
        $this->groups = new ArrayCollection();
        $this->documents = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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

    public function getStartsOn(): ?\DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function setStartsOn(?\DateTimeImmutable $startsOn): static
    {
        $this->startsOn = $startsOn;

        return $this;
    }

    public function getEndsOn(): ?\DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function setEndsOn(?\DateTimeImmutable $endsOn): static
    {
        $this->endsOn = $endsOn;

        return $this;
    }

    public function getState(): DossierState
    {
        return $this->state;
    }

    public function isPublished(): bool
    {
        return DossierState::Published === $this->state;
    }

    /**
     * Publication is one-way and idempotent: a second call changes nothing, and there is no
     * unpublish() to pair with it - see the enum.
     */
    public function publish(): static
    {
        if (!$this->isPublished()) {
            $this->state = DossierState::Published;
            $this->publishedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /** @return Collection<int, User> */
    public function getValidators(): Collection
    {
        return $this->validators;
    }

    public function addValidator(User $validator): static
    {
        if (!$this->validators->contains($validator)) {
            $this->validators->add($validator);
        }

        return $this;
    }

    /**
     * Removing a validateur, except the créateur.
     *
     * The refusal is here and not in the controller because it is a property of the dossier: the
     * créateur is « validateur d'office », and a screen that offered the cross on their chip would
     * be offering a gesture the model does not have.
     */
    public function removeValidator(User $validator): static
    {
        if (!$this->isCreator($validator)) {
            $this->validators->removeElement($validator);
        }

        return $this;
    }

    public function isValidator(User $user): bool
    {
        return $this->validators->contains($user);
    }

    public function isCreator(User $user): bool
    {
        $creator = $this->getCreatedBy();

        if (null === $creator) {
            return false;
        }

        // Identity first, ids second, and never ids alone: a dossier being composed has not been
        // flushed yet, so `getId()` answers null on both sides and `null === null` would make
        // everybody its créateur.
        return $creator === $user || (null !== $creator->getId() && $creator->getId() === $user->getId());
    }

    /** @return Collection<int, DossierTargetProgram> */
    public function getTargetPrograms(): Collection
    {
        return $this->targetPrograms;
    }

    /** Called by DossierTargetProgram's constructor; use addTargetProgram() to add one. */
    public function addTarget(DossierTargetProgram $target): static
    {
        if (!$this->targetPrograms->contains($target)) {
            $this->targetPrograms->add($target);
        }

        return $this;
    }

    /**
     * Find-or-create, and never a duplicate: a formation is named once, and asking for it again
     * hands back the row that already carries its options rather than starting a second, empty one.
     */
    public function addTargetProgram(Program $program): DossierTargetProgram
    {
        return $this->targetFor($program) ?? new DossierTargetProgram($this, $program);
    }

    public function removeTargetProgram(Program $program): static
    {
        $target = $this->targetFor($program);

        if (null !== $target) {
            $this->targetPrograms->removeElement($target);
        }

        return $this;
    }

    public function targetFor(Program $program): ?DossierTargetProgram
    {
        foreach ($this->targetPrograms as $target) {
            if ($target->getProgram() === $program
                || (null !== $program->getId() && $target->getProgram()?->getId() === $program->getId())) {
                return $target;
            }
        }

        return null;
    }

    /**
     * The formations themselves, without their narrowing - for the screens that only need to know
     * which classes are involved.
     *
     * @return list<Program>
     */
    public function targetedPrograms(): array
    {
        $programs = [];

        foreach ($this->targetPrograms as $target) {
            $program = $target->getProgram();

            if (null !== $program) {
                $programs[] = $program;
            }
        }

        return $programs;
    }

    /** @return Collection<int, User> */
    public function getTargetStudents(): Collection
    {
        return $this->targetStudents;
    }

    public function addTargetStudent(User $student): static
    {
        if (!$this->targetStudents->contains($student)) {
            $this->targetStudents->add($student);
        }

        return $this;
    }

    public function removeTargetStudent(User $student): static
    {
        $this->targetStudents->removeElement($student);

        return $this;
    }

    /** @return Collection<int, DossierGroup> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(DossierGroup $group): static
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
        }

        return $this;
    }

    public function removeGroup(DossierGroup $group): static
    {
        $this->groups->removeElement($group);

        return $this;
    }

    /** @return Collection<int, DossierDocument> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(DossierDocument $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
        }

        return $this;
    }

    public function removeDocument(DossierDocument $document): static
    {
        $this->documents->removeElement($document);

        return $this;
    }

    /**
     * The documents of one group, or - with null - the ones filed nowhere.
     *
     * @return list<DossierDocument>
     */
    public function documentsOfGroup(?DossierGroup $group): array
    {
        $documents = array_values(array_filter(
            $this->documents->toArray(),
            static fn (DossierDocument $document): bool => $document->getGroup() === $group,
        ));

        // Sorted here rather than through a Criteria: #[ORM\OrderBy] only applies to a collection
        // Doctrine loaded, and this runs just as often on a dossier being composed in memory.
        usort($documents, static fn (DossierDocument $a, DossierDocument $b): int => [$a->getPosition(), (int) $a->getId()] <=> [$b->getPosition(), (int) $b->getId()]);

        return $documents;
    }

    /**
     * The span the dossier actually asks for — from the earliest date limite of its documents to
     * the latest, which is what a cible reads as « Période » rather than the two dates the wizard
     * declared.
     *
     * Read from the documents rather than from `startsOn`/`endsOn` because those two are a label
     * somebody typed, and the dates a student has to hold are the ones written on the pieces. A
     * document with no date limite is simply not part of the span; a dossier where none of them
     * carries one has no span at all, and the screens print a dash.
     *
     * @return array{start: ?\DateTimeImmutable, end: ?\DateTimeImmutable}
     */
    public function documentDueSpan(): array
    {
        $dates = [];

        foreach ($this->documents as $document) {
            $due = $document->getDueOn();

            if (null !== $due) {
                $dates[] = $due;
            }
        }

        if ([] === $dates) {
            return ['start' => null, 'end' => null];
        }

        return ['start' => min($dates), 'end' => max($dates)];
    }

    /** How many documents nobody may skip - the denominator of every avancement on this feature. */
    public function requiredDocumentCount(): int
    {
        return $this->documents->filter(static fn (DossierDocument $d): bool => $d->isRequired())->count();
    }

    /** The next free slot at the end of the document list. */
    public function nextDocumentPosition(): int
    {
        $positions = $this->documents->map(static fn (DossierDocument $d): int => $d->getPosition())->toArray();

        return [] === $positions ? 0 : max($positions) + 1;
    }

    public function nextGroupPosition(): int
    {
        $positions = $this->groups->map(static fn (DossierGroup $g): int => $g->getPosition())->toArray();

        return [] === $positions ? 0 : max($positions) + 1;
    }
}
