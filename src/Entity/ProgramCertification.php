<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CertificationKind;
use App\Repository\ProgramCertificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The national certification a Program prepares, per Option - "Bac+3 Info" prepares the CDA title
 * on its CDA option and the AIS title on its AIS one, each with its own RNCP code, so the
 * certification cannot hang off the Program alone. Same (program, option) shape as
 * InternshipOptionLegalName / InternshipOptionExamModality.
 *
 * $option is nullable for the single-certification case: one row with a null option means "this
 * Program prepares this certification, whatever the student's option". A Program therefore has
 * either exactly one null-option row, or one row per certifying option - the unique constraint
 * covers the second case, the first is a matter of not creating both (see
 * ProgramCertificationRepository::findForOption(), which prefers the option-specific row).
 *
 * Deliberately NOT a shared referential entity keyed by RNCP code: the codes are re-entered for
 * every year's Program, exactly like the skill referential this accompanies. That duplication is
 * the accepted trade-off (see App\Referential\BachelorInfoTsfCatalog), and it keeps a Program's
 * certification editable without touching another year's.
 */
#[ORM\Entity(repositoryClass: ProgramCertificationRepository::class)]
#[ORM\Table(name: 'program_certification')]
#[ORM\UniqueConstraint(name: 'program_certification_unique', columns: ['program_id', 'option_id'])]
class ProgramCertification
{
    // No AuditableTrait, like the sibling per-Option override rows
    // (InternshipOptionLegalName, InternshipOptionExamModality): the row's existence is the
    // setting, and there is no inactivation lifecycle to trace.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    // Null means "applies to the whole Program" - see the class docblock.
    #[ORM\ManyToOne(targetEntity: Option::class)]
    #[ORM\JoinColumn(name: 'option_id', nullable: true)]
    private ?Option $option = null;

    /** Without the kind prefix: "Administrateur d'Infrastructures Sécurisées". */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label;

    #[ORM\Column(length: 20, enumType: CertificationKind::class)]
    private CertificationKind $kind = CertificationKind::TitrePro;

    #[ORM\Column(name: 'rncp_code', length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $rncpCode = null;

    /** France Compétences level (5 = Bac+2, 6 = Bac+3/4, 7 = Bac+5). */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 8)]
    private ?int $level = null;

    /** The body that awards the title, e.g. "Ministère du Travail". */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $certifier = null;

    /**
     * The ministry's code of a titre professionnel, "TP-01281" - not the RNCP code. With the
     * millésime, it is what the ECF booklet prints in its footer and finds a candidate's booklet by.
     */
    #[ORM\Column(name: 'title_code', length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $titleCode = null;

    /** The version of the titre's referential, "04". */
    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Length(max: 10)]
    private ?string $millesime = null;

    // The three dates the ECF booklet's cover prints under the titre. They are the titre's, so two
    // options of one formation each carry their own.
    #[ORM\Column(name: 'decree_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decreeDate = null;

    #[ORM\Column(name: 'journal_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $journalDate = null;

    #[ORM\Column(name: 'effective_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $effectiveDate = null;

    // The « Date de mise à jour » of the ministry's template footer - the template's, not ours.
    #[ORM\Column(name: 'model_updated_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $modelUpdatedDate = null;

    public function __construct(Program $program, ?Option $option, string $label)
    {
        $this->program = $program;
        $this->option = $option;
        $this->label = $label;
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getKind(): CertificationKind
    {
        return $this->kind;
    }

    public function setKind(CertificationKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getRncpCode(): ?string
    {
        return $this->rncpCode;
    }

    public function setRncpCode(?string $rncpCode): static
    {
        $this->rncpCode = $rncpCode;

        return $this;
    }

    public function getLevel(): ?int
    {
        return $this->level;
    }

    public function setLevel(?int $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getCertifier(): ?string
    {
        return $this->certifier;
    }

    public function setCertifier(?string $certifier): static
    {
        $this->certifier = $certifier;

        return $this;
    }

    public function getTitleCode(): ?string
    {
        return $this->titleCode;
    }

    public function setTitleCode(?string $titleCode): static
    {
        $this->titleCode = $titleCode;

        return $this;
    }

    public function getMillesime(): ?string
    {
        return $this->millesime;
    }

    public function setMillesime(?string $millesime): static
    {
        $this->millesime = $millesime;

        return $this;
    }

    public function getDecreeDate(): ?\DateTimeImmutable
    {
        return $this->decreeDate;
    }

    public function setDecreeDate(?\DateTimeImmutable $decreeDate): static
    {
        $this->decreeDate = $decreeDate;

        return $this;
    }

    public function getJournalDate(): ?\DateTimeImmutable
    {
        return $this->journalDate;
    }

    public function setJournalDate(?\DateTimeImmutable $journalDate): static
    {
        $this->journalDate = $journalDate;

        return $this;
    }

    public function getEffectiveDate(): ?\DateTimeImmutable
    {
        return $this->effectiveDate;
    }

    public function setEffectiveDate(?\DateTimeImmutable $effectiveDate): static
    {
        $this->effectiveDate = $effectiveDate;

        return $this;
    }

    public function getModelUpdatedDate(): ?\DateTimeImmutable
    {
        return $this->modelUpdatedDate;
    }

    public function setModelUpdatedDate(?\DateTimeImmutable $modelUpdatedDate): static
    {
        $this->modelUpdatedDate = $modelUpdatedDate;

        return $this;
    }

    /** The title line of a fiche: "TP - Administrateur d'Infrastructures Sécurisées". */
    public function getFullLabel(): string
    {
        $abbreviation = $this->kind->abbreviation();

        return '' === $abbreviation ? $this->label : $abbreviation.' - '.$this->label;
    }
}
