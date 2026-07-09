<?php

namespace App\Entity;

use App\Repository\ProgramTeacherOptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tags a teacher as attached to one of the program's own options - same purpose as
 * ProgramStudentOption, for teachers instead of students (e.g. a teacher only teaching the SLAM
 * option's sessions within a Program that also offers SISR).
 */
#[ORM\Entity(repositoryClass: ProgramTeacherOptionRepository::class)]
#[ORM\Table(name: 'program_teacher_option')]
#[ORM\UniqueConstraint(name: 'program_teacher_option_unique', columns: ['program_id', 'teacher_id', 'option_id'])]
class ProgramTeacherOption
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
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    #[Assert\NotNull]
    private ?User $teacher = null;

    #[ORM\ManyToOne(targetEntity: Option::class)]
    #[ORM\JoinColumn(name: 'option_id', nullable: false)]
    #[Assert\NotNull]
    private ?Option $option = null;

    public function __construct(Program $program, User $teacher, Option $option)
    {
        $this->program = $program;
        $this->teacher = $teacher;
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

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    public function getOption(): ?Option
    {
        return $this->option;
    }
}
