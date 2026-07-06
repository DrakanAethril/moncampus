<?php

namespace App\Entity;

use App\Repository\ModalityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * How students follow a Program (e.g. alternance, initial), across one or more Programs.
 */
#[ORM\Entity(repositoryClass: ModalityRepository::class)]
#[ORM\Table(name: 'modality')]
class Modality extends AbstractStructureNode
{
    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class, inversedBy: 'modalities')]
    #[ORM\JoinTable(name: 'modality_program')]
    private Collection $programs;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->programs = new ArrayCollection();
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
