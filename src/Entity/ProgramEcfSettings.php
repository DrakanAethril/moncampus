<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProgramEcfSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a formation says of its livret d'évaluations passées en cours de formation, whatever the
 * option: whether it keeps one, and the organisme and lieu the cover prints. Edited in UFA >
 * Formations > {formation} > « Dénomination » (App\Service\Ecf\EcfSettingsEditor). A singleton row
 * per Program, created on first save, like InternshipProgramInfo.
 *
 * The titre itself - label, sigle, level, code titre, millésime, and the dates of its arrêté - is
 * not kept here: it differs from one option to the next, and is read from the certification of
 * each student's option (App\Service\Ecf\EcfTitle).
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
