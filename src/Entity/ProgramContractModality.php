<?php

namespace App\Entity;

use App\Repository\ProgramContractModalityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Program's override of one ContractType's center-level default modalities (e.g. an
 * apprenticeship-mode Program writing its own terms instead of the shared default). Presence of
 * a row IS the override - there's no "use default" flag, the row is simply deleted to fall back
 * to ContractType::getDefaultModalitiesHtml(), same convention as InternshipOptionLegalName /
 * InternshipOptionExamModality. Replaces InternshipProgramInfo's old
 * termsConditionsProText/termsConditionsApprentissageText free-text fields (migrated into rows
 * here, one per Program per contract type that had non-blank text).
 */
#[ORM\Entity(repositoryClass: ProgramContractModalityRepository::class)]
#[ORM\Table(name: 'program_contract_modality')]
#[ORM\UniqueConstraint(name: 'program_contract_modality_unique', columns: ['program_id', 'contract_type_id'])]
class ProgramContractModality
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: ContractType::class)]
    #[ORM\JoinColumn(name: 'contract_type_id', nullable: false)]
    #[Assert\NotNull]
    private ?ContractType $contractType = null;

    #[ORM\Column(name: 'modalities_html', type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $modalitiesHtml = null;

    public function __construct(Program $program, ContractType $contractType, string $modalitiesHtml)
    {
        $this->program = $program;
        $this->contractType = $contractType;
        $this->modalitiesHtml = $modalitiesHtml;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getContractType(): ?ContractType
    {
        return $this->contractType;
    }

    public function getModalitiesHtml(): ?string
    {
        return $this->modalitiesHtml;
    }

    public function setModalitiesHtml(string $modalitiesHtml): static
    {
        $this->modalitiesHtml = $modalitiesHtml;

        return $this;
    }
}
