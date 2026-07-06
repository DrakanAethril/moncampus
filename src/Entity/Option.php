<?php

namespace App\Entity;

use App\Repository\OptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A specialization students can follow across one or more Programs (e.g. SLAM, SISR, Latin).
 */
#[ORM\Entity(repositoryClass: OptionRepository::class)]
#[ORM\Table(name: '`option`')]
class Option extends AbstractStructureNode
{
    #[ORM\Column(name: 'short_name', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $shortName;

    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class, inversedBy: 'options')]
    #[ORM\JoinTable(name: 'option_program')]
    private Collection $programs;

    public function __construct(string $name, string $shortName)
    {
        parent::__construct($name);
        $this->shortName = $shortName;
        $this->programs = new ArrayCollection();
    }

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function setShortName(string $shortName): static
    {
        $this->shortName = $shortName;

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
            $program->getOptions()->add($this);
        }

        return $this;
    }

    public function removeProgram(Program $program): static
    {
        if ($this->programs->removeElement($program)) {
            $program->getOptions()->removeElement($this);
        }

        return $this;
    }
}
