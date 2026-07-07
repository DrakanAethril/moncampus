<?php

namespace App\Entity;

use App\Repository\LaptopLoanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One lend/return cycle for a Laptop. The full lending history of a laptop (or of a borrower) is
 * simply every LaptopLoan row involving it, ordered by lentAt - there is no separate log/audit
 * table. A laptop is "currently on loan" exactly when it has a LaptopLoan with returnedAt still
 * null; only one such row may exist per laptop at a time (enforced in the controller).
 */
#[ORM\Entity(repositoryClass: LaptopLoanRepository::class)]
#[ORM\Table(name: 'laptop_loan')]
class LaptopLoan
{
    /** @var list<string> */
    public const RETURN_CONDITIONS = ['ok', 'damaged', 'lost'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Laptop::class)]
    #[ORM\JoinColumn(name: 'laptop_id', nullable: false)]
    #[Assert\NotNull]
    private ?Laptop $laptop = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'borrower_id', nullable: false)]
    #[Assert\NotNull]
    private ?User $borrower = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'lent_by_id', nullable: false)]
    #[Assert\NotNull]
    private ?User $lentBy = null;

    #[ORM\Column(name: 'lent_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lentAt;

    #[ORM\Column(name: 'due_at', type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(name: 'lent_state_notes', type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $lentStateNotes = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'returned_by_id', nullable: true)]
    private ?User $returnedBy = null;

    #[ORM\Column(name: 'returned_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    #[ORM\Column(name: 'return_state_notes', type: Types::TEXT, nullable: true)]
    private ?string $returnStateNotes = null;

    #[ORM\Column(name: 'return_condition', length: 255, nullable: true)]
    #[Assert\Choice(choices: self::RETURN_CONDITIONS)]
    private ?string $returnCondition = null;

    public function __construct(Laptop $laptop)
    {
        $this->laptop = $laptop;
        $this->lentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLaptop(): ?Laptop
    {
        return $this->laptop;
    }

    public function getBorrower(): ?User
    {
        return $this->borrower;
    }

    public function setBorrower(?User $borrower): static
    {
        $this->borrower = $borrower;

        return $this;
    }

    public function getLentBy(): ?User
    {
        return $this->lentBy;
    }

    public function setLentBy(?User $lentBy): static
    {
        $this->lentBy = $lentBy;

        return $this;
    }

    public function getLentAt(): \DateTimeImmutable
    {
        return $this->lentAt;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(?\DateTimeImmutable $dueAt): static
    {
        $this->dueAt = $dueAt;

        return $this;
    }

    public function getLentStateNotes(): string
    {
        return $this->lentStateNotes;
    }

    public function setLentStateNotes(string $lentStateNotes): static
    {
        $this->lentStateNotes = $lentStateNotes;

        return $this;
    }

    public function getReturnedBy(): ?User
    {
        return $this->returnedBy;
    }

    public function setReturnedBy(?User $returnedBy): static
    {
        $this->returnedBy = $returnedBy;

        return $this;
    }

    public function getReturnedAt(): ?\DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function setReturnedAt(?\DateTimeImmutable $returnedAt): static
    {
        $this->returnedAt = $returnedAt;

        return $this;
    }

    public function getReturnStateNotes(): ?string
    {
        return $this->returnStateNotes;
    }

    public function setReturnStateNotes(?string $returnStateNotes): static
    {
        $this->returnStateNotes = $returnStateNotes;

        return $this;
    }

    public function getReturnCondition(): ?string
    {
        return $this->returnCondition;
    }

    public function setReturnCondition(?string $returnCondition): static
    {
        $this->returnCondition = $returnCondition;

        return $this;
    }

    public function isReturned(): bool
    {
        return null !== $this->returnedAt;
    }

    public function isOverdue(): bool
    {
        return !$this->isReturned() && null !== $this->dueAt && $this->dueAt < new \DateTimeImmutable();
    }
}
