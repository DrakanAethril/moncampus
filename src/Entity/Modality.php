<?php

namespace App\Entity;

use App\Repository\ModalityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * How students follow a Cohort (e.g. alternance, initial), across one or more Cohorts.
 */
#[ORM\Entity(repositoryClass: ModalityRepository::class)]
#[ORM\Table(name: 'modality')]
class Modality extends AbstractStructureNode
{
    /** @var Collection<int, Cohort> */
    #[ORM\ManyToMany(targetEntity: Cohort::class, inversedBy: 'modalities')]
    #[ORM\JoinTable(name: 'modality_cohort')]
    private Collection $cohorts;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->cohorts = new ArrayCollection();
    }

    /** @return Collection<int, Cohort> */
    public function getCohorts(): Collection
    {
        return $this->cohorts;
    }

    public function addCohort(Cohort $cohort): static
    {
        if (!$this->cohorts->contains($cohort)) {
            $this->cohorts->add($cohort);
            $cohort->getModalities()->add($this);
        }

        return $this;
    }

    public function removeCohort(Cohort $cohort): static
    {
        if ($this->cohorts->removeElement($cohort)) {
            $cohort->getModalities()->removeElement($this);
        }

        return $this;
    }
}
