<?php

namespace App\Entity;

use App\Repository\ProgramReferentTeacherOptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tags a referent teacher as attached to one of the program's own options - same purpose as
 * ProgramTeacherOption, for the referent-teacher role instead of the plain teacher role (e.g. a
 * referent only responsible for the SLAM option's students within a Program that also offers
 * SISR). A separate table rather than reusing ProgramTeacherOption because referent status is
 * itself independent of - though always a subset of - Program::$teachers (see
 * Program::addReferentTeacher()), so "which options is this referent responsible for" is a
 * distinct fact from "which options does this teacher teach".
 */
#[ORM\Entity(repositoryClass: ProgramReferentTeacherOptionRepository::class)]
#[ORM\Table(name: 'program_referent_teacher_option')]
#[ORM\UniqueConstraint(name: 'program_referent_teacher_option_unique', columns: ['program_id', 'referent_teacher_id', 'option_id'])]
class ProgramReferentTeacherOption
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
    #[ORM\JoinColumn(name: 'referent_teacher_id', nullable: false)]
    #[Assert\NotNull]
    private ?User $referentTeacher = null;

    #[ORM\ManyToOne(targetEntity: Option::class)]
    #[ORM\JoinColumn(name: 'option_id', nullable: false)]
    #[Assert\NotNull]
    private ?Option $option = null;

    public function __construct(Program $program, User $referentTeacher, Option $option)
    {
        $this->program = $program;
        $this->referentTeacher = $referentTeacher;
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

    public function getReferentTeacher(): ?User
    {
        return $this->referentTeacher;
    }

    public function getOption(): ?Option
    {
        return $this->option;
    }
}
