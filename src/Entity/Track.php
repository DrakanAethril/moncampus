<?php

namespace App\Entity;

use App\Repository\TrackRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A course of study within a Section (e.g. general, technologique, SIO).
 */
#[ORM\Entity(repositoryClass: TrackRepository::class)]
#[ORM\Table(name: 'track')]
class Track extends AbstractStructureNode
{
    #[ORM\ManyToOne(targetEntity: Section::class, inversedBy: 'tracks')]
    #[ORM\JoinColumn(name: 'section_id', nullable: false)]
    #[Assert\NotNull]
    private Section $section;

    /** @var Collection<int, Cohort> */
    #[ORM\OneToMany(targetEntity: Cohort::class, mappedBy: 'track')]
    private Collection $cohorts;

    public function __construct(string $name, Section $section)
    {
        parent::__construct($name);
        $this->cohorts = new ArrayCollection();
        $this->setSection($section);
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function setSection(Section $section): static
    {
        $this->section = $section;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a
        // fresh query, not automatically from setting the owning side.
        if (!$section->getTracks()->contains($this)) {
            $section->getTracks()->add($this);
        }

        return $this;
    }

    /** @return Collection<int, Cohort> */
    public function getCohorts(): Collection
    {
        return $this->cohorts;
    }
}
