<?php

namespace App\Entity;

use App\Repository\OptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A specialization students can follow across one or more Cohorts (e.g. SLAM, SISR, Latin).
 */
#[ORM\Entity(repositoryClass: OptionRepository::class)]
#[ORM\Table(name: '`option`')]
class Option extends AbstractStructureNode
{
    #[ORM\Column(name: 'short_name', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $shortName;

    /** @var Collection<int, Cohort> */
    #[ORM\ManyToMany(targetEntity: Cohort::class, inversedBy: 'options')]
    #[ORM\JoinTable(name: 'option_cohort')]
    private Collection $cohorts;

    public function __construct(string $name, string $shortName)
    {
        parent::__construct($name);
        $this->shortName = $shortName;
        $this->cohorts = new ArrayCollection();
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

    /** @return Collection<int, Cohort> */
    public function getCohorts(): Collection
    {
        return $this->cohorts;
    }

    public function addCohort(Cohort $cohort): static
    {
        if (!$this->cohorts->contains($cohort)) {
            $this->cohorts->add($cohort);
            $cohort->getOptions()->add($this);
        }

        return $this;
    }

    public function removeCohort(Cohort $cohort): static
    {
        if ($this->cohorts->removeElement($cohort)) {
            $cohort->getOptions()->removeElement($this);
        }

        return $this;
    }
}
