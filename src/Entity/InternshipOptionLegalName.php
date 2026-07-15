<?php

namespace App\Entity;

use App\Repository\InternshipOptionLegalNameRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Program's per-Option override of the Livret Alternant cover-page legal name (e.g. "Bac+3
 * Info" has both a CDA and an AIS option, each legally chartered under its own name). Presence of
 * a row IS the override - there's no "use default" flag, the row is simply deleted to fall back to
 * InternshipProgramInfo::getLegalName() (itself falling back to Program::getName()), same
 * reasoning as InternshipOptionExamModality.
 */
#[ORM\Entity(repositoryClass: InternshipOptionLegalNameRepository::class)]
#[ORM\Table(name: 'internship_option_legal_name')]
#[ORM\UniqueConstraint(name: 'internship_option_legal_name_unique', columns: ['program_id', 'option_id'])]
class InternshipOptionLegalName
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

    #[ORM\Column(name: 'legal_name', length: 255)]
    #[Assert\NotBlank]
    private ?string $legalName = null;

    public function __construct(Program $program, Option $option, string $legalName)
    {
        $this->program = $program;
        $this->option = $option;
        $this->legalName = $legalName;
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

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function setLegalName(string $legalName): static
    {
        $this->legalName = $legalName;

        return $this;
    }
}
