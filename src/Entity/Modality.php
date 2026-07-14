<?php

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

    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class, inversedBy: 'modalities')]
    #[ORM\JoinTable(name: 'modality_program')]
    private Collection $programs;

    public function __construct(string $name, string $color)
    {
        parent::__construct($name);
        $this->color = $color;
        $this->programs = new ArrayCollection();
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
}
