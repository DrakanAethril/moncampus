<?php

namespace App\Entity;

use App\Repository\InternshipProgramInfoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-program data shown on the Livret Alternant booklet: the legal name shown on the cover
 * (falls back to Program::$name), an exam modality description, and the two terms & conditions
 * variants (contrat pro / apprentissage) - a singleton row per Program (no inactiveDate/
 * deactivate lifecycle, same reasoning as InternshipFormationCenter).
 */
#[ORM\Entity(repositoryClass: InternshipProgramInfoRepository::class)]
#[ORM\Table(name: 'internship_program_info')]
class InternshipProgramInfo
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private ?Program $program = null;

    // The program's legal name shown on the booklet's cover page in place of Program::$name when
    // set - null falls back to Program::$name (see App\Service\InternshipBookletBuilder). Per-
    // Option overrides live in InternshipOptionLegalName, same "presence of a row is the override"
    // convention as InternshipOptionExamModality.
    #[ORM\Column(name: 'legal_name', length: 255, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(name: 'exam_modality_text', type: Types::TEXT, nullable: true)]
    private ?string $examModalityText = null;

    #[ORM\Column(name: 'terms_conditions_pro_text', type: Types::TEXT, nullable: true)]
    private ?string $termsConditionsProText = null;

    #[ORM\Column(name: 'terms_conditions_apprentissage_text', type: Types::TEXT, nullable: true)]
    private ?string $termsConditionsApprentissageText = null;

    public function __construct(Program $program)
    {
        $this->program = $program;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function setLegalName(?string $legalName): static
    {
        $this->legalName = $legalName;

        return $this;
    }

    public function getExamModalityText(): ?string
    {
        return $this->examModalityText;
    }

    public function setExamModalityText(?string $examModalityText): static
    {
        $this->examModalityText = $examModalityText;

        return $this;
    }

    public function getTermsConditionsProText(): ?string
    {
        return $this->termsConditionsProText;
    }

    public function setTermsConditionsProText(?string $termsConditionsProText): static
    {
        $this->termsConditionsProText = $termsConditionsProText;

        return $this;
    }

    public function getTermsConditionsApprentissageText(): ?string
    {
        return $this->termsConditionsApprentissageText;
    }

    public function setTermsConditionsApprentissageText(?string $termsConditionsApprentissageText): static
    {
        $this->termsConditionsApprentissageText = $termsConditionsApprentissageText;

        return $this;
    }
}
