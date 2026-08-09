<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ModalityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * How students follow a Program (e.g. alternance, initial), across one or more Programs.
 */
#[ORM\Entity(repositoryClass: ModalityRepository::class)]
#[ORM\Table(name: 'modality')]
class Modality extends AbstractStructureNode
{
    // Unlike Option::$shortName, optional here - existing rows predate this field and most
    // Modality names (e.g. "Alternance", "Initial") are already short enough not to need one.
    // Falls back to the full name for display wherever it's blank - see ProgramType's choice_label.
    #[ORM\Column(name: 'short_name', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $shortName = null;

    // Same purpose as Option::$color (a hex string driving a UI swatch), for the same kind of
    // badge use.
    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $color;

    // Marks this as THE alternance Modality (there should be exactly one, admin-flagged here
    // rather than matched by name, which would break on a rename or duplicate) - checked on the
    // Program form to reveal its UFA section.
    #[ORM\Column(name: 'is_alternance', options: ['default' => false])]
    private bool $isAlternance = false;

    // Le pendant de $isAlternance pour le stage. "Traineeship" et non "internship" : dans ce
    // codebase, Internship* désigne déjà tout le module livret de l'alternance
    // (InternshipTutorLink et consorts), un Modality::$isInternship voudrait donc dire le
    // contraire de ce que son nom laisse croire. Aucun comportement n'y est encore accroché - le
    // drapeau existe pour être posé dès maintenant sur les modalités concernées.
    #[ORM\Column(name: 'is_traineeship', options: ['default' => false])]
    private bool $isTraineeship = false;

    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class, inversedBy: 'modalities')]
    #[ORM\JoinTable(name: 'modality_program')]
    private Collection $programs;

    // Which Periods this modality applies to - a Period with no modalities at all applies to every
    // modality (see Period::$modalities), so this is a restriction, not the primary link.
    /** @var Collection<int, Period> */
    #[ORM\ManyToMany(targetEntity: Period::class, inversedBy: 'modalities')]
    #[ORM\JoinTable(name: 'modality_period')]
    private Collection $periods;

    public function __construct(string $name, string $color)
    {
        parent::__construct($name);
        $this->color = $color;
        $this->programs = new ArrayCollection();
        $this->periods = new ArrayCollection();
    }

    public function getShortName(): ?string
    {
        return $this->shortName;
    }

    public function setShortName(?string $shortName): static
    {
        $this->shortName = $shortName;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function isAlternance(): bool
    {
        return $this->isAlternance;
    }

    public function setIsAlternance(bool $isAlternance): static
    {
        $this->isAlternance = $isAlternance;

        return $this;
    }

    public function isTraineeship(): bool
    {
        return $this->isTraineeship;
    }

    public function setIsTraineeship(bool $isTraineeship): static
    {
        $this->isTraineeship = $isTraineeship;

        return $this;
    }

    /** @return Collection<int, Program> */
    public function getPrograms(): Collection
    {
        return $this->programs;
    }

    public function addProgram(Program $program): static
    {
        if (!$this->programs->contains($program)) {
            $this->programs->add($program);
            $program->getModalities()->add($this);
        }

        return $this;
    }

    public function removeProgram(Program $program): static
    {
        if ($this->programs->removeElement($program)) {
            $program->getModalities()->removeElement($this);
        }

        return $this;
    }

    /** @return Collection<int, Period> */
    public function getPeriods(): Collection
    {
        return $this->periods;
    }

    public function addPeriod(Period $period): static
    {
        if (!$this->periods->contains($period)) {
            $this->periods->add($period);
            $period->getModalities()->add($this);
        }

        return $this;
    }

    public function removePeriod(Period $period): static
    {
        if ($this->periods->removeElement($period)) {
            $period->getModalities()->removeElement($this);
        }

        return $this;
    }
}
