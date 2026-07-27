<?php

namespace App\Entity;

use App\Repository\ProgramStudentModalityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tags a student as following one of the program's own modalities - mirrors ProgramStudentOption
 * for Modality instead of Option (see that class's docblock for the underlying reasoning).
 */
#[ORM\Entity(repositoryClass: ProgramStudentModalityRepository::class)]
#[ORM\Table(name: 'program_student_modality')]
#[ORM\UniqueConstraint(name: 'program_student_modality_unique', columns: ['program_id', 'student_id', 'modality_id'])]
class ProgramStudentModality
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

    #[ORM\ManyToOne(targetEntity: Modality::class)]
    #[ORM\JoinColumn(name: 'modality_id', nullable: false)]
    #[Assert\NotNull]
    private ?Modality $modality = null;

    public function __construct(Program $program, User $student, Modality $modality)
    {
        $this->program = $program;
        $this->student = $student;
        $this->modality = $modality;
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

    public function getModality(): ?Modality
    {
        return $this->modality;
    }
}
