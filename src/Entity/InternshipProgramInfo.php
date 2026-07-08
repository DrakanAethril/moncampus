<?php

namespace App\Entity;

use App\Repository\InternshipProgramInfoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-program free-text blocks shown on the Livret Alternant booklet: exam modality
 * description and the two terms & conditions variants (contrat pro / apprentissage) - a
 * singleton row per Program (no inactiveDate/deactivate lifecycle, same reasoning as
 * InternshipFormationCenter).
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

    #[ORM\Column(name: 'exam_modality_text', type: Types::TEXT, nullable: true)]
    private ?string $examModalityText = null;

    #[ORM\Column(name: 'terms_conditions_pro_text', type: Types::TEXT, nullable: true)]
    private ?string $termsConditionsProText = null;

    #[ORM\Column(name: 'terms_conditions_apprentissage_text', type: Types::TEXT, nullable: true)]
    private ?string $termsConditionsApprentissageText = null;

    // S3 object key (not a URL - keeps the bucket/CloudFront domain changeable without a data
    // migration) for the booklet's cover-page graphic, image or PDF - see
    // App\Service\FileUploadService, App\Service\ProgramInfoAsset.
    #[ORM\Column(name: 'cover_page_key', length: 255, nullable: true)]
    private ?string $coverPageKey = null;

    // Same as $coverPageKey, for the alternance calendar graphic.
    #[ORM\Column(name: 'calendar_key', length: 255, nullable: true)]
    private ?string $calendarKey = null;

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

    public function getCoverPageKey(): ?string
    {
        return $this->coverPageKey;
    }

    public function setCoverPageKey(?string $coverPageKey): static
    {
        $this->coverPageKey = $coverPageKey;

        return $this;
    }

    public function getCalendarKey(): ?string
    {
        return $this->calendarKey;
    }

    public function setCalendarKey(?string $calendarKey): static
    {
        $this->calendarKey = $calendarKey;

        return $this;
    }
}
