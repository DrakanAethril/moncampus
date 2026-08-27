<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AttendanceState;
use App\Repository\AttendanceStatementLineRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One student's answer on one statement, and the whole row is three columns wide on purpose: the
 * statement, the student, one of three states.
 *
 * **No motive, no absence date, no counter** (§6). This is the table somebody will one day want to
 * add « justifiée » or « nombre de demi-journées » to, and the answer is no: the moment it carries
 * either, MonCampus holds an attendance register - with everything that follows from that, none of
 * which belongs to a game.
 */
#[ORM\Entity(repositoryClass: AttendanceStatementLineRepository::class)]
#[ORM\Table(name: 'attendance_statement_line')]
#[ORM\UniqueConstraint(name: 'uniq_attendance_line', columns: ['statement_id', 'student_id'])]
class AttendanceStatementLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AttendanceStatement::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'statement_id', nullable: false, onDelete: 'CASCADE')]
    private AttendanceStatement $statement;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(length: 20, enumType: AttendanceState::class)]
    private AttendanceState $state = AttendanceState::Clean;

    public function __construct(AttendanceStatement $statement, User $student, AttendanceState $state = AttendanceState::Clean)
    {
        $this->statement = $statement;
        $this->student = $student;
        $this->state = $state;
        $statement->addLine($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatement(): AttendanceStatement
    {
        return $this->statement;
    }

    public function getStudent(): User
    {
        return $this->student;
    }

    public function getState(): AttendanceState
    {
        return $this->state;
    }

    public function setState(AttendanceState $state): static
    {
        $this->state = $state;

        return $this;
    }
}
