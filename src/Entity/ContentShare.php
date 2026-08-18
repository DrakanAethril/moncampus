<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContentShareScope;
use App\Enum\ContentShareSubject;
use App\Repository\ContentShareRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One teacher handing one named item to colleagues - see
 * design/validated/content-sharing-between-teachers.md.
 *
 * « Un partage donne à lire ; une duplication donne à posséder. » This row creates **no data in the
 * recipient's library**: it creates a right to read the original. The recipient's own click is what
 * writes, and what it writes is theirs.
 *
 * **Five nullable foreign keys, exactly one filled, and never a `(target_type, target_id)` pair.**
 * This repository already paid for that answer once and wrote down why (design/validated/
 * file-library.md, "The link model"): a polymorphic pair "reads well and it lies" - nothing deletes
 * its rows when the host disappears. The failure would be worse here, a share row surviving its
 * deleted séquence granting access to nothing and still showing in a colleague's list. Five
 * `ON DELETE CASCADE` foreign keys cannot drift. And the shape is not an invention: LibraryResource
 * already carries three nullable parent FKs with exactly one set.
 *
 * The constructor is private and there are **five named factories**, so "exactly one subject" is a
 * fact of the class rather than a convention a caller can forget.
 *
 * **`revoked_at`, never a `DELETE`.** A revoked share has usually already been duplicated: an author
 * asking « à qui l'ai-je donné ? » is asking about the past, and a deleted row answers nothing.
 * Second, un-revoking becomes a click instead of an audience to rebuild. Revocation removes access
 * **and only access**: the copy stays.
 *
 * Two JSON columns rather than a third table, since the audience tables are the only ones this
 * design opens: $duplicatedBy answers « dupliqué 3 fois » and « dupliquée le 5 août » on the
 * author's own row, and $dismissedBy is what lets a recipient close a line the author has withdrawn.
 * Neither is derivable - a duplication is deliberately a copy with no link back to its source.
 */
#[ORM\Entity(repositoryClass: ContentShareRepository::class)]
#[ORM\Table(name: 'content_share')]
#[ORM\Index(name: 'idx_content_share_owner', columns: ['owner_id'])]
#[ORM\Index(name: 'idx_content_share_scope', columns: ['scope'])]
class ContentShare
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SequenceTemplate::class)]
    #[ORM\JoinColumn(name: 'sequence_template_id', nullable: true, onDelete: 'CASCADE')]
    private ?SequenceTemplate $sequenceTemplate = null;

    #[ORM\ManyToOne(targetEntity: SeanceTemplate::class)]
    #[ORM\JoinColumn(name: 'seance_template_id', nullable: true, onDelete: 'CASCADE')]
    private ?SeanceTemplate $seanceTemplate = null;

    #[ORM\ManyToOne(targetEntity: QuizTemplate::class)]
    #[ORM\JoinColumn(name: 'quiz_template_id', nullable: true, onDelete: 'CASCADE')]
    private ?QuizTemplate $quizTemplate = null;

    #[ORM\ManyToOne(targetEntity: FileLibraryNode::class)]
    #[ORM\JoinColumn(name: 'library_node_id', nullable: true, onDelete: 'CASCADE')]
    private ?FileLibraryNode $libraryNode = null;

    #[ORM\ManyToOne(targetEntity: Progression::class)]
    #[ORM\JoinColumn(name: 'progression_id', nullable: true, onDelete: 'CASCADE')]
    private ?Progression $progression = null;

    /** Who shared - always the item's owner, since nobody else may (App\Service\ContentShareAccess). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 20, enumType: ContentShareScope::class)]
    private ContentShareScope $scope;

    /** « pourquoi je te l'envoie » - optional, and the only free text of the whole feature. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'content_share_user')]
    #[ORM\JoinColumn(name: 'share_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    private Collection $users;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'content_share_group')]
    #[ORM\JoinColumn(name: 'share_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'group_id', onDelete: 'CASCADE')]
    private Collection $groups;

    /**
     * Who has duplicated this share, and when - user id => ISO-8601 date.
     *
     * @var array<int, string>
     */
    #[ORM\Column(name: 'duplicated_by', type: Types::JSON)]
    private array $duplicatedBy = [];

    /**
     * Who has closed the line in their « Reçus » - a revoked share stays visible until its reader
     * dismisses it, rather than vanishing without a word from a list they were working from.
     *
     * @var list<int>
     */
    #[ORM\Column(name: 'dismissed_by', type: Types::JSON)]
    private array $dismissedBy = [];

    private function __construct(User $owner, ContentShareScope $scope)
    {
        $this->owner = $owner;
        $this->scope = $scope;
        $this->users = new ArrayCollection();
        $this->groups = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public static function ofSequence(SequenceTemplate $sequenceTemplate, User $owner, ContentShareScope $scope): self
    {
        $share = new self($owner, $scope);
        $share->sequenceTemplate = $sequenceTemplate;

        return $share;
    }

    public static function ofSeance(SeanceTemplate $seanceTemplate, User $owner, ContentShareScope $scope): self
    {
        $share = new self($owner, $scope);
        $share->seanceTemplate = $seanceTemplate;

        return $share;
    }

    public static function ofQuiz(QuizTemplate $quizTemplate, User $owner, ContentShareScope $scope): self
    {
        $share = new self($owner, $scope);
        $share->quizTemplate = $quizTemplate;

        return $share;
    }

    public static function ofFile(FileLibraryNode $libraryNode, User $owner, ContentShareScope $scope): self
    {
        $share = new self($owner, $scope);
        $share->libraryNode = $libraryNode;

        return $share;
    }

    public static function ofProgression(Progression $progression, User $owner, ContentShareScope $scope): self
    {
        $share = new self($owner, $scope);
        $share->progression = $progression;

        return $share;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Which of the five the row carries. The `??` chain is exhaustive because the constructor is
     * private: no caller can build a share without going through one of the factories above.
     */
    public function getSubject(): ContentShareSubject
    {
        return match (true) {
            null !== $this->sequenceTemplate => ContentShareSubject::Sequence,
            null !== $this->seanceTemplate => ContentShareSubject::Seance,
            null !== $this->quizTemplate => ContentShareSubject::Quiz,
            null !== $this->libraryNode => ContentShareSubject::File,
            default => ContentShareSubject::Progression,
        };
    }

    /** What the row of « Reçus » reads - the item's own name, never a name this table stores. */
    public function getSubjectTitle(): string
    {
        return match ($this->getSubject()) {
            ContentShareSubject::Sequence => (string) $this->sequenceTemplate?->getTitre(),
            ContentShareSubject::Seance => (string) $this->seanceTemplate?->getTitre(),
            ContentShareSubject::Quiz => (string) $this->quizTemplate?->getName(),
            ContentShareSubject::File => (string) $this->libraryNode?->getName(),
            ContentShareSubject::Progression => (string) $this->progression?->getTopic()?->getName(),
        };
    }

    public function getSequenceTemplate(): ?SequenceTemplate
    {
        return $this->sequenceTemplate;
    }

    public function getSeanceTemplate(): ?SeanceTemplate
    {
        return $this->seanceTemplate;
    }

    public function getQuizTemplate(): ?QuizTemplate
    {
        return $this->quizTemplate;
    }

    public function getLibraryNode(): ?FileLibraryNode
    {
        return $this->libraryNode;
    }

    public function getProgression(): ?Progression
    {
        return $this->progression;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getScope(): ContentShareScope
    {
        return $this->scope;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = null !== $note && '' !== trim($note) ? trim($note) : null;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revoke(): self
    {
        $this->revokedAt = new \DateTimeImmutable();

        return $this;
    }

    /** « Rétablir » - the second reason revocation is a date and not a DELETE. */
    public function restore(): self
    {
        $this->revokedAt = null;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): self
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
        }

        return $this;
    }

    public function removeUser(User $user): self
    {
        $this->users->removeElement($user);

        return $this;
    }

    /** @return list<int> */
    public function getUserIds(): array
    {
        $ids = [];

        foreach ($this->users as $user) {
            $id = $user->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return Collection<int, Group> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(Group $group): self
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
        }

        return $this;
    }

    public function removeGroup(Group $group): self
    {
        $this->groups->removeElement($group);

        return $this;
    }

    /** @return list<int> */
    public function getGroupIds(): array
    {
        $ids = [];

        foreach ($this->groups as $group) {
            $id = $group->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return array<int, string> */
    public function getDuplicatedBy(): array
    {
        return $this->duplicatedBy;
    }

    public function getDuplicationCount(): int
    {
        return \count($this->duplicatedBy);
    }

    public function duplicatedAtBy(int $userId): ?\DateTimeImmutable
    {
        $date = $this->duplicatedBy[$userId] ?? null;

        return null === $date ? null : new \DateTimeImmutable($date);
    }

    public function markDuplicatedBy(int $userId): self
    {
        $this->duplicatedBy[$userId] = (new \DateTimeImmutable())->format(\DATE_ATOM);

        return $this;
    }

    /** @return list<int> */
    public function getDismissedBy(): array
    {
        return $this->dismissedBy;
    }

    public function isDismissedBy(int $userId): bool
    {
        return \in_array($userId, $this->dismissedBy, true);
    }

    public function dismissBy(int $userId): self
    {
        if (!$this->isDismissedBy($userId)) {
            $this->dismissedBy[] = $userId;
        }

        return $this;
    }
}
