<?php

namespace App\Entity;

use App\Repository\CohortRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A group of students progressing together within a Track (e.g. SIO1, CG1, 3emeA).
 */
#[ORM\Entity(repositoryClass: CohortRepository::class)]
#[ORM\Table(name: 'cohort')]
class Cohort extends AbstractStructureNode
{
    // Nullable in PHP (unlike the DB column) purely so the form data mapper can pass through a
    // transiently-null value while re-applying the "track" field's submitted data after
    // empty_data runs, without a TypeError - #[Assert\NotNull] still rejects it before persist().
    #[ORM\ManyToOne(targetEntity: Track::class, inversedBy: 'cohorts')]
    #[ORM\JoinColumn(name: 'track_id', nullable: false)]
    #[Assert\NotNull]
    private ?Track $track = null;

    /** @var Collection<int, Formation> */
    #[ORM\OneToMany(targetEntity: Formation::class, mappedBy: 'cohort')]
    private Collection $formations;

    public function __construct(string $name, Track $track)
    {
        parent::__construct($name);
        $this->formations = new ArrayCollection();
        $this->setTrack($track);
    }

    public function getTrack(): ?Track
    {
        return $this->track;
    }

    public function setTrack(?Track $track): static
    {
        $this->track = $track;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a
        // fresh query, not automatically from setting the owning side.
        if (null !== $track && !$track->getCohorts()->contains($this)) {
            $track->getCohorts()->add($this);
        }

        return $this;
    }

    /** @return Collection<int, Formation> */
    public function getFormations(): Collection
    {
        return $this->formations;
    }
}
