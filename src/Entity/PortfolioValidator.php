<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PortfolioValidatorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A teacher designated by the administration to validate the portfolios of one class **and** one
 * option (design/validated/portfolio.md §1).
 *
 * This is the only door to deciding anything about a portfolio: no administrator, no referent
 * teacher, no teacher of the class passes without a row here - and a row alone is not enough
 * either, the person must still teach in the formation (App\Service\Portfolio\PortfolioValidators).
 *
 * A withdrawal is a dated deactivation, never a delete: the decisions already taken hold (R2b), and
 * the journal can still say who was designated when.
 */
#[ORM\Entity(repositoryClass: PortfolioValidatorRepository::class)]
#[ORM\Table(name: 'portfolio_validator')]
#[ORM\UniqueConstraint(name: 'portfolio_validator_unique', columns: ['program_id', 'option_id', 'teacher_id'])]
class PortfolioValidator
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false, onDelete: 'CASCADE')]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: Option::class)]
    #[ORM\JoinColumn(name: 'option_id', nullable: false)]
    private ?Option $option = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    private ?User $teacher = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'inactivated_by_id', nullable: true)]
    private ?User $inactivatedBy = null;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(Program $program, Option $option, User $teacher, User $createdBy)
    {
        $this->program = $program;
        $this->option = $option;
        $this->teacher = $teacher;
        $this->createdBy = $createdBy;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getOption(): ?Option
    {
        return $this->option;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactivatedBy(): ?User
    {
        return $this->inactivatedBy;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function isActive(): bool
    {
        return null === $this->inactiveDate;
    }

    public function withdraw(User $by): void
    {
        $this->inactiveDate = new \DateTimeImmutable();
        $this->inactivatedBy = $by;
    }

    /** Designated again: the same row, dated anew. */
    public function reinstate(User $by): void
    {
        $this->inactiveDate = null;
        $this->inactivatedBy = null;
        $this->createdBy = $by;
        $this->creationDate = new \DateTimeImmutable();
    }
}
