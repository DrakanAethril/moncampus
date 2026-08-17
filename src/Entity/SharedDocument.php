<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SharedDocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One file of a teacher's library, put at a class's disposal.
 *
 * It is a **tenth link onto App\Entity\FileLibraryNode**, and behaves exactly like the nine others
 * (App\Service\FileLibraryLinks): the file exists once, weighs once, and deleting it from the
 * library withdraws it from every class it had been shared with. Nothing is copied here - the row
 * carries who shared what with whom, never bytes.
 *
 * Four things are targeted rather than one:
 *
 * - **`program`** is the class, and it is the only mandatory target. On its own it means the whole
 *   class.
 * - **`options` and `modalities`** narrow it. They are two independent filters and they **intersect**:
 *   an empty set means "every option" / "every modality", and a share carrying both is read by the
 *   students who match both. That is what « préciser le ciblage » asks for - a refinement, not a
 *   second audience. App\Service\SharedDocumentAudience is where that rule is written once.
 * - **`topic`** is the matière the document belongs to. It is the student list's first sort key, and
 *   it is deliberately not tied to the timetable: a document may be filed under a first-semester
 *   matière in the middle of the second, which is precisely the case the teacher asks for.
 *
 * **`visibleFrom`/`visibleUntil` are both nullable and default to null**, which is the unlimited
 * window - the default the teacher gets without touching anything. A bound that is set is inclusive
 * of its own minute and checked at read time, never by a cron: an expired share stays a row, so the
 * teacher keeps seeing it among the file's usages and can tell "I withdrew it" from "it never
 * existed".
 *
 * The date the student sees as *mise à disposition* is `availableAt()`: the opening bound when there
 * is one, the creation date otherwise. Sorting on the creation date alone would put a document
 * scheduled for next month at the top of the list today - and it is not available today.
 */
#[ORM\Entity(repositoryClass: SharedDocumentRepository::class)]
#[ORM\Table(name: 'shared_document')]
#[ORM\Index(name: 'idx_shared_doc_program', columns: ['program_id'])]
#[ORM\Index(name: 'idx_shared_doc_node', columns: ['library_node_id'])]
#[ORM\Index(name: 'idx_shared_doc_teacher', columns: ['teacher_id'])]
class SharedDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FileLibraryNode::class)]
    #[ORM\JoinColumn(name: 'library_node_id', nullable: false, onDelete: 'CASCADE')]
    private FileLibraryNode $libraryNode;

    /**
     * Who shared it - the second grouping the student list offers, and the owner of the library the
     * file came from. Kept as its own column rather than read through the node: the file may later
     * be shared by somebody it was linked to, and the roster the student reads must stay true to who
     * actually put it there.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    private User $teacher;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private Program $program;

    /**
     * Nullable in the column and required by the form: a share written before a matière existed - or
     * whose matière has since been deleted - must stay readable rather than take the screen down.
     * The student list buckets those under « Sans matière ».
     */
    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', nullable: true, onDelete: 'SET NULL')]
    private ?Topic $topic = null;

    /** @var Collection<int, Option> */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'shared_document_option')]
    private Collection $options;

    /** @var Collection<int, Modality> */
    #[ORM\ManyToMany(targetEntity: Modality::class)]
    #[ORM\JoinTable(name: 'shared_document_modality')]
    private Collection $modalities;

    #[ORM\Column(name: 'visible_from', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $visibleFrom = null;

    #[ORM\Column(name: 'visible_until', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThan(propertyPath: 'visibleFrom', message: 'sharedDocumentWindowOrderError')]
    private ?\DateTimeImmutable $visibleUntil = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct(FileLibraryNode $libraryNode, User $teacher, Program $program)
    {
        $this->libraryNode = $libraryNode;
        $this->teacher = $teacher;
        $this->program = $program;
        $this->options = new ArrayCollection();
        $this->modalities = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLibraryNode(): FileLibraryNode
    {
        return $this->libraryNode;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    public function getTopic(): ?Topic
    {
        return $this->topic;
    }

    public function setTopic(?Topic $topic): self
    {
        $this->topic = $topic;

        return $this;
    }

    /** @return Collection<int, Option> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(Option $option): self
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
        }

        return $this;
    }

    public function removeOption(Option $option): self
    {
        $this->options->removeElement($option);

        return $this;
    }

    /** @return Collection<int, Modality> */
    public function getModalities(): Collection
    {
        return $this->modalities;
    }

    public function addModality(Modality $modality): self
    {
        if (!$this->modalities->contains($modality)) {
            $this->modalities->add($modality);
        }

        return $this;
    }

    public function removeModality(Modality $modality): self
    {
        $this->modalities->removeElement($modality);

        return $this;
    }

    public function getVisibleFrom(): ?\DateTimeImmutable
    {
        return $this->visibleFrom;
    }

    public function setVisibleFrom(?\DateTimeImmutable $visibleFrom): self
    {
        $this->visibleFrom = $visibleFrom;

        return $this;
    }

    public function getVisibleUntil(): ?\DateTimeImmutable
    {
        return $this->visibleUntil;
    }

    public function setVisibleUntil(?\DateTimeImmutable $visibleUntil): self
    {
        $this->visibleUntil = $visibleUntil;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    /** The « mis à disposition le » the student reads, and the list's date sort. */
    public function availableAt(): \DateTimeImmutable
    {
        return $this->visibleFrom ?? $this->creationDate;
    }

    public function hasWindow(): bool
    {
        return null !== $this->visibleFrom || null !== $this->visibleUntil;
    }

    /** Unlimited by default; both bounds inclusive of the minute they name. */
    public function isVisibleAt(\DateTimeImmutable $moment): bool
    {
        if (null !== $this->visibleFrom && $moment < $this->visibleFrom) {
            return false;
        }

        return null === $this->visibleUntil || $moment <= $this->visibleUntil;
    }
}
