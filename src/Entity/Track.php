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
    // Nullable in PHP (unlike the DB column) purely so the form data mapper can pass through a
    // transiently-null value while re-applying the "section" field's submitted data after
    // empty_data runs, without a TypeError - #[Assert\NotNull] still rejects it before persist().
    #[ORM\ManyToOne(targetEntity: Section::class, inversedBy: 'tracks')]
    #[ORM\JoinColumn(name: 'section_id', nullable: false)]
    #[Assert\NotNull]
    private ?Section $section = null;

    /** @var Collection<int, Cohort> */
    #[ORM\OneToMany(targetEntity: Cohort::class, mappedBy: 'track')]
    private Collection $cohorts;

    public function __construct(string $name, Section $section)
    {
        parent::__construct($name);
        $this->cohorts = new ArrayCollection();
        $this->setSection($section);
    }

    public function getSection(): ?Section
    {
        return $this->section;
    }

    public function setSection(?Section $section): static
    {
        $this->section = $section;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a
        // fresh query, not automatically from setting the owning side.
        if (null !== $section && !$section->getTracks()->contains($this)) {
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
