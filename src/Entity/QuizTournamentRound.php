<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuizTournamentRoundStatus;
use App\Repository\QuizTournamentRoundRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A round in class (design/validated/quiz-tournament.md §8): a teacher of a targeted formation opens
 * it for that class, optionally narrowed to **one Option** of the class **or one group** of a saved
 * « Création de groupes » lot (GroupBatch + the group's index in it, read on the day of the round:
 * only its members still enrolled and eligible may join). The students present join; the teacher
 * closes and pairs (App\Service\QuizTournament\QuizTournamentRoundPairing), or cancels. A round never
 * expires by itself.
 *
 * Its duels are ordinary duels (QuizTournamentDuel::$round) - they count for everything.
 */
#[ORM\Entity(repositoryClass: QuizTournamentRoundRepository::class)]
#[ORM\Table(name: 'quiz_tournament_round')]
class QuizTournamentRound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizTournament::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuizTournament $tournament;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Program $program;

    #[ORM\ManyToOne(targetEntity: Option::class)]
    #[ORM\JoinColumn(name: 'restricted_option_id', nullable: true, onDelete: 'SET NULL')]
    private ?Option $restrictedOption = null;

    #[ORM\ManyToOne(targetEntity: GroupBatch::class)]
    #[ORM\JoinColumn(name: 'restricted_group_batch_id', nullable: true, onDelete: 'SET NULL')]
    private ?GroupBatch $restrictedGroupBatch = null;

    // The group's index in GroupBatch::$groups, 0-based; « Groupe 1 » on screen.
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $restrictedGroupIndex = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $teacher;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pairedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'quiz_tournament_round_participant')]
    #[ORM\JoinColumn(name: 'round_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    private Collection $participants;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'exempt_user_id', nullable: true)]
    private ?User $exempt = null;

    public function __construct(QuizTournament $tournament, Program $program, User $teacher, \DateTimeImmutable $openedAt)
    {
        $this->tournament = $tournament;
        $this->program = $program;
        $this->teacher = $teacher;
        $this->openedAt = $openedAt;
        $this->participants = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTournament(): QuizTournament
    {
        return $this->tournament;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function getRestrictedOption(): ?Option
    {
        return $this->restrictedOption;
    }

    public function getRestrictedGroupBatch(): ?GroupBatch
    {
        return $this->restrictedGroupBatch;
    }

    public function getRestrictedGroupIndex(): ?int
    {
        return $this->restrictedGroupIndex;
    }

    /** An Option **or** a group, never both: choosing one clears the other. */
    public function restrictToOption(?Option $option): static
    {
        $this->restrictedOption = $option;
        $this->restrictedGroupBatch = null;
        $this->restrictedGroupIndex = null;

        return $this;
    }

    public function restrictToGroup(GroupBatch $batch, int $groupIndex): static
    {
        $this->restrictedOption = null;
        $this->restrictedGroupBatch = $batch;
        $this->restrictedGroupIndex = $groupIndex;

        return $this;
    }

    /**
     * The user ids of the restricting group, as the lot holds them today; null when the round is not
     * narrowed to a group (or the lot has since been deleted).
     *
     * @return list<int>|null
     */
    public function restrictedGroupMemberIds(): ?array
    {
        if (null === $this->restrictedGroupBatch || null === $this->restrictedGroupIndex) {
            return null;
        }

        return $this->restrictedGroupBatch->getGroups()[$this->restrictedGroupIndex] ?? [];
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getPairedAt(): ?\DateTimeImmutable
    {
        return $this->pairedAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getStatus(): QuizTournamentRoundStatus
    {
        return match (true) {
            null !== $this->cancelledAt => QuizTournamentRoundStatus::Cancelled,
            null !== $this->pairedAt => QuizTournamentRoundStatus::Paired,
            default => QuizTournamentRoundStatus::Open,
        };
    }

    public function isOpen(): bool
    {
        return QuizTournamentRoundStatus::Open === $this->getStatus();
    }

    /** @return Collection<int, User> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function join(User $student): static
    {
        if (!$this->participants->contains($student)) {
            $this->participants->add($student);
        }

        return $this;
    }

    public function leave(User $student): static
    {
        $this->participants->removeElement($student);

        return $this;
    }

    public function markPaired(\DateTimeImmutable $at, ?User $exempt): static
    {
        $this->pairedAt = $at;
        $this->exempt = $exempt;

        return $this;
    }

    public function cancel(\DateTimeImmutable $at): static
    {
        $this->cancelledAt = $at;

        return $this;
    }

    public function getExempt(): ?User
    {
        return $this->exempt;
    }
}
