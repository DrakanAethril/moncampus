<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProgramEcfSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A formation's « Livret ECF » tab (UFA > Formations > {formation}): whether the formation keeps
 * the livret d'évaluations passées en cours de formation, the dates the ministry's template prints
 * on its cover and footer, and the organisme. A singleton row per Program, created on first save,
 * like InternshipProgramInfo.
 *
 * The titre itself - label, sigle, level, code titre, millésime - is not kept here: it is read
 * from « Dénomination » for each student's option (App\Service\Ecf\EcfTitle).
 */
#[ORM\Entity(repositoryClass: ProgramEcfSettingsRepository::class)]
#[ORM\Table(name: 'program_ecf_settings')]
class ProgramEcfSettings
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false, onDelete: 'CASCADE')]
    private ?Program $program = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(name: 'decree_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decreeDate = null;

    #[ORM\Column(name: 'journal_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $journalDate = null;

    #[ORM\Column(name: 'effective_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $effectiveDate = null;

    // The « Date de mise à jour » of the ministry's template footer - the template's, not ours.
    #[ORM\Column(name: 'model_updated_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $modelUpdatedDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $organisation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $place = null;

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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

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

    public function getOrganisation(): ?string
    {
        return $this->organisation;
    }

    public function setOrganisation(?string $organisation): static
    {
        $this->organisation = $organisation;

        return $this;
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function setPlace(?string $place): static
    {
        $this->place = $place;

        return $this;
    }
}
