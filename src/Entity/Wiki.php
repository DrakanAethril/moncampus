<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WikiType;
use App\Repository\WikiRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A wiki: a private workspace holding a tree of folders and pages (design/validated/wiki.md).
 *
 * Two kinds, and the difference is structural rather than cosmetic:
 *
 * - **Personal** - one per owner (the UNIQUE index on owner_id says so), created only when its
 *   owner confirms it on the invitation page, never automatically and never by anybody else. It
 *   refuses members and programs *at the entity level*: sharing always means creating a Shared
 *   wiki, which is exactly what a teacher does to work with colleagues.
 * - **Shared** - members (students AND/OR colleagues, never scoped to a Program, so a cross-class
 *   wiki is legal) and/or whole classes.
 *
 * Who may read one is App\Service\WikiAccess's business and nothing else's. The one thing worth
 * knowing here is that "has a student audience" - the fact that flips a wiki between the
 * supervised and the private regime - is computed live from $members and $programs and is
 * deliberately not a column: a stored flag would be one more thing to keep in sync with an
 * audience the settings screen edits.
 *
 * Not to be confused with App\Entity\DocumentationArticle: the Base documentaire is a flat,
 * published, read-by-the-campus channel whose audience is "audiences AND perimeter". The two share
 * the S3 upload pattern and the HugeRTE integration, nothing else.
 */
#[ORM\Entity(repositoryClass: WikiRepository::class)]
#[ORM\Table(name: 'wiki')]
#[ORM\Index(name: 'idx_wiki_type', columns: ['type'])]
class Wiki
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 20, enumType: WikiType::class)]
    private WikiType $type;

    // Non-null iff Personal, and UNIQUE: a user has at most one personal wiki, and the index is
    // what makes "at most one" true even if two tabs press "Créer mon wiki" at the same moment.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: true, unique: true, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'wiki_member')]
    private Collection $members;

    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class)]
    #[ORM\JoinTable(name: 'wiki_program')]
    private Collection $programs;

    /** @var Collection<int, WikiNode> */
    #[ORM\OneToMany(mappedBy: 'wiki', targetEntity: WikiNode::class, cascade: ['persist', 'remove'])]
    private Collection $nodes;

    #[ORM\Column]
    private bool $archived = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $title, WikiType $type, ?User $createdBy = null)
    {
        $this->title = $title;
        $this->type = $type;
        $this->createdBy = $createdBy;
        $this->members = new ArrayCollection();
        $this->programs = new ArrayCollection();
        $this->nodes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** The personal wiki of one person - the only way to build one, owner and creator being one. */
    public static function personalFor(User $owner): self
    {
        $wiki = new self($owner->getDisplayName() ?? $owner->getUsername(), WikiType::Personal, $owner);
        $wiki->owner = $owner;

        return $wiki;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getType(): WikiType
    {
        return $this->type;
    }

    public function isPersonal(): bool
    {
        return WikiType::Personal === $this->type;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    /** @return Collection<int, User> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    /**
     * A personal wiki refuses members outright rather than quietly ignoring them: "a personal wiki
     * cannot be shared" is a property of the model, and a caller that gets it wrong should hear
     * about it here rather than produce a wiki nobody can explain.
     */
    public function addMember(User $member): static
    {
        $this->assertShared('members');

        if (!$this->members->contains($member)) {
            $this->members->add($member);
        }

        return $this;
    }

    public function removeMember(User $member): static
    {
        $this->members->removeElement($member);

        return $this;
    }

    /** @return Collection<int, Program> */
    public function getPrograms(): Collection
    {
        return $this->programs;
    }

    public function addProgram(Program $program): static
    {
        $this->assertShared('programs');

        if (!$this->programs->contains($program)) {
            $this->programs->add($program);
        }

        return $this;
    }

    public function removeProgram(Program $program): static
    {
        $this->programs->removeElement($program);

        return $this;
    }

    /** @return Collection<int, WikiNode> */
    public function getNodes(): Collection
    {
        return $this->nodes;
    }

    public function addNode(WikiNode $node): static
    {
        if (!$this->nodes->contains($node)) {
            $this->nodes->add($node);
            $node->setWiki($this);
        }

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): static
    {
        $this->archived = $archived;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** @return list<int> */
    public function getMemberIds(): array
    {
        $ids = [];

        foreach ($this->members as $member) {
            $id = $member->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return list<list<string>> */
    public function getMemberRoles(): array
    {
        $roles = [];

        foreach ($this->members as $member) {
            $roles[] = $member->getRoles();
        }

        return $roles;
    }

    private function assertShared(string $what): void
    {
        if (WikiType::Personal === $this->type) {
            throw new \LogicException(\sprintf('A personal wiki accepts no %s - create a shared wiki instead.', $what));
        }
    }
}
