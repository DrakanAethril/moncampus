<?php

namespace App\Entity;

use App\Repository\InternshipOptionExamModalityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Program's per-Option override of the Livret Alternant exam modality text (e.g. "Bac+3 Info"
 * has both a CDA and an AIS option, each with its own RNCP exam format). Presence of a row IS the
 * override - there's no "use default" flag, the row is simply deleted to fall back to
 * InternshipProgramInfo::getExamModalityText(), same reasoning as ProgramLessonTypeCost.
 */
#[ORM\Entity(repositoryClass: InternshipOptionExamModalityRepository::class)]
#[ORM\Table(name: 'internship_option_exam_modality')]
#[ORM\UniqueConstraint(name: 'internship_option_exam_modality_unique', columns: ['program_id', 'option_id'])]
class InternshipOptionExamModality
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: Option::class)]
    #[ORM\JoinColumn(name: 'option_id', nullable: false)]
    #[Assert\NotNull]
    private ?Option $option = null;

    #[ORM\Column(name: 'exam_modality_text', type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $examModalityText = null;

    public function __construct(Program $program, Option $option, string $examModalityText)
    {
        $this->program = $program;
        $this->option = $option;
        $this->examModalityText = $examModalityText;
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

    public function getExamModalityText(): ?string
    {
        return $this->examModalityText;
    }

    public function setExamModalityText(string $examModalityText): static
    {
        $this->examModalityText = $examModalityText;

        return $this;
    }
}
