<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PortfolioDepositState;
use App\Enum\PortfolioExam;
use App\Repository\PortfolioDepositRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A dossier handed in for one examination - E5 or E6 - frozen at the moment it is made (R9).
 *
 * The snapshot and the generated files never change afterwards: only the conformity check
 * (annexe VI-2 or VII-2), its outcome and the endorsement do. **The deadline locks nothing** (R8):
 * a late deposit is accepted and marked « hors délai », exactly like the livret's deadlines - the
 * équipe decides what a late dossier means, the platform only records it.
 *
 * What goes to the commission is always read from the latest *endorsed* deposit.
 */
#[ORM\Entity(repositoryClass: PortfolioDepositRepository::class)]
#[ORM\Table(name: 'portfolio_deposit')]
#[ORM\Index(name: 'portfolio_deposit_exam_idx', columns: ['portfolio_id', 'exam', 'deposited_at'])]
class PortfolioDeposit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Portfolio::class)]
    #[ORM\JoinColumn(name: 'portfolio_id', nullable: false, onDelete: 'CASCADE')]
    private ?Portfolio $portfolio = null;

    /** The formation of the year the dossier was deposited in - whose deadline and validateurs apply. */
    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private ?Program $program = null;

    #[ORM\Column(length: 5, enumType: PortfolioExam::class)]
    private PortfolioExam $exam;

    #[ORM\Column(nullable: true)]
    private ?int $session = null;

    #[ORM\Column(name: 'deposited_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $depositedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'deposited_by_id', nullable: false)]
    private ?User $depositedBy = null;

    #[ORM\Column(name: 'deadline', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deadline = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $late = false;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $snapshot = [];

    #[ORM\Column(name: 'xlsx_key', length: 255, nullable: true)]
    private ?string $xlsxKey = null;

    #[ORM\Column(name: 'pdf_key', length: 255, nullable: true)]
    private ?string $pdfKey = null;

    #[ORM\Column(length: 20, enumType: PortfolioDepositState::class)]
    private PortfolioDepositState $state = PortfolioDepositState::Deposited;

    /** @var array<string, bool> checklist key => conforming */
    #[ORM\Column(type: Types::JSON)]
    private array $checklist = [];

    #[ORM\Column(name: 'check_comment', type: Types::TEXT, nullable: true)]
    private ?string $checkComment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'checked_by_id', nullable: true)]
    private ?User $checkedBy = null;

    #[ORM\Column(name: 'checked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(Portfolio $portfolio, Program $program, PortfolioExam $exam, User $depositedBy, ?\DateTimeImmutable $deadline, array $snapshot)
    {
        $this->portfolio = $portfolio;
        $this->program = $program;
        $this->exam = $exam;
        $this->session = $program->getPortfolioExamSession();
        $this->depositedBy = $depositedBy;
        $this->depositedAt = new \DateTimeImmutable();
        $this->deadline = $deadline;
        $this->late = null !== $deadline && $this->depositedAt > $deadline;
        $this->snapshot = $snapshot;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPortfolio(): ?Portfolio
    {
        return $this->portfolio;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getExam(): PortfolioExam
    {
        return $this->exam;
    }

    public function getSession(): ?int
    {
        return $this->session;
    }

    public function getDepositedAt(): \DateTimeImmutable
    {
        return $this->depositedAt;
    }

    public function getDepositedBy(): ?User
    {
        return $this->depositedBy;
    }

    public function getDeadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    public function isLate(): bool
    {
        return $this->late;
    }

    /** @return array<string, mixed> */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    public function getXlsxKey(): ?string
    {
        return $this->xlsxKey;
    }

    public function setXlsxKey(?string $xlsxKey): static
    {
        $this->xlsxKey = $xlsxKey;

        return $this;
    }

    public function getPdfKey(): ?string
    {
        return $this->pdfKey;
    }

    public function setPdfKey(?string $pdfKey): static
    {
        $this->pdfKey = $pdfKey;

        return $this;
    }

    public function getState(): PortfolioDepositState
    {
        return $this->state;
    }

    public function isEndorsed(): bool
    {
        return PortfolioDepositState::Endorsed === $this->state;
    }

    /** @return array<string, bool> */
    public function getChecklist(): array
    {
        return $this->checklist;
    }

    public function getCheckComment(): ?string
    {
        return $this->checkComment;
    }

    public function getCheckedBy(): ?User
    {
        return $this->checkedBy;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    /**
     * The conformity check: every item ticked endorses the deposit, any item left unticked asks the
     * student to regularise, and then the reason is mandatory.
     *
     * @param array<string, bool> $checklist
     *
     * @throws \InvalidArgumentException when a non-conforming deposit carries no reason
     */
    public function check(User $checker, array $checklist, ?string $comment): void
    {
        $comment = null === $comment ? null : trim($comment);
        $conforming = [] !== $checklist && !\in_array(false, $checklist, true);

        if (!$conforming && (null === $comment || '' === $comment)) {
            throw new \InvalidArgumentException('A deposit to regularise must say why.');
        }

        $this->checklist = $checklist;
        $this->checkComment = '' === $comment ? null : $comment;
        $this->checkedBy = $checker;
        $this->checkedAt = new \DateTimeImmutable();
        $this->state = $conforming ? PortfolioDepositState::Endorsed : PortfolioDepositState::ToRegularise;
    }
}
