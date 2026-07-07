<?php

namespace App\Entity;

use App\Repository\ProgramStudentOptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tags a student as enrolled in one of the program's own options - a program's students and
 * options are each already tracked independently (Program::$students, Program::$options), this
 * is the missing link between the two, needed to split per-option documents (e.g. signature
 * sheets) by which students actually attend which option's sessions.
 */
#[ORM\Entity(repositoryClass: ProgramStudentOptionRepository::class)]
#[ORM\Table(name: 'program_student_option')]
#[ORM\UniqueConstraint(name: 'program_student_option_unique', columns: ['program_id', 'student_id', 'option_id'])]
class ProgramStudentOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    #[Assert\NotNull]
    private ?User $student = null;

    #[ORM\ManyToOne(targetEntity: Option::class)]
    #[ORM\JoinColumn(name: 'option_id', nullable: false)]
    #[Assert\NotNull]
    private ?Option $option = null;

    public function __construct(Program $program, User $student, Option $option)
    {
        $this->program = $program;
        $this->student = $student;
        $this->option = $option;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getOption(): ?Option
    {
        return $this->option;
    }
}
