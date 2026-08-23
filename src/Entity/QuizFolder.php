<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuizFolderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One folder of a teacher's quiz library - the classement a library of two hundred quizzes needs,
 * and deliberately the file library's tree rather than a second idea of one (see
 * App\Entity\FileLibraryNode).
 *
 * Three differences with that one, each of which follows from what a quiz is:
 *
 * - **a folder holds no bytes**, so there is one table for folders and the quizzes stay in
 *   `quiz_template` with a nullable `folder_id`. The single-table trick of the file library exists
 *   because a file and a folder are both nodes of one storage tree; a QuizTemplate is an entity of
 *   its own, already owned, already linked from séances and séquences;
 * - **there is no corbeille.** A QuizTemplate is hard-deleted in this application, so a folder must
 *   never take quizzes with it: deleting one promotes its content one level up
 *   (App\Service\QuizFolderManager::delete());
 * - **`name` is unique among siblings and no index enforces it** - the same reason recorded on
 *   FileLibraryNode: MySQL treats every NULL as distinct, so UNIQUE (owner, parent, name) would
 *   constrain nothing at root level, which is the level a teacher meets first.
 *
 * `path` holds the ancestors' ids, '/12/48/', so "everything under folder 48" is one
 * `LIKE '/12/48/%'`, and moving a subtree rewrites `path`/`depth` on the folder and its descendants.
 */
#[ORM\Entity(repositoryClass: QuizFolderRepository::class)]
#[ORM\Table(name: 'quiz_folder')]
#[ORM\Index(name: 'idx_quiz_folder_owner_parent', columns: ['owner_id', 'parent_id'])]
#[ORM\Index(name: 'idx_quiz_folder_path', columns: ['path'])]
class QuizFolder
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 768)]
    private string $path = '/';

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $depth = 0;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct(User $owner, string $name)
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->children = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, self> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function setDepth(int $depth): self
    {
        $this->depth = $depth;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    /** The date the listing shows: when the folder last changed, falling back to its creation. */
    public function getModifiedAt(): \DateTimeImmutable
    {
        return $this->lastUpdatedDate ?? $this->creationDate;
    }

    /**
     * The materialised path a child of this folder carries - '/' at the root, '/3/7/' under folder 7
     * of folder 3. Also the LIKE prefix a subtree query needs, which is why it is written once here.
     */
    public function childPath(): string
    {
        return $this->path.$this->id.'/';
    }
}
