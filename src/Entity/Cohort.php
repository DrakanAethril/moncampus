<?php

namespace App\Entity;

use App\Repository\CohortRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A group of students progressing together within a Track (e.g. SIO1, CG1, 3emeA).
 */
#[ORM\Entity(repositoryClass: CohortRepository::class)]
#[ORM\Table(name: 'cohort')]
class Cohort extends AbstractStructureNode
{
    #[ORM\ManyToOne(targetEntity: Track::class, inversedBy: 'cohorts')]
    #[ORM\JoinColumn(name: 'track_id', nullable: false)]
    #[Assert\NotNull]
    private Track $track;

    public function __construct(string $name, Track $track)
    {
        parent::__construct($name);
        $this->setTrack($track);
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function setTrack(Track $track): static
    {
        $this->track = $track;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a
        // fresh query, not automatically from setting the owning side.
        if (!$track->getCohorts()->contains($this)) {
            $track->getCohorts()->add($this);
        }

        return $this;
    }
}
