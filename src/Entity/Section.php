<?php

namespace App\Entity;

use App\Repository\SectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Top level of the school structure hierarchy (e.g. college, lycee, campus).
 */
#[ORM\Entity(repositoryClass: SectionRepository::class)]
#[ORM\Table(name: 'section')]
class Section extends AbstractStructureNode
{
    /** @var Collection<int, Track> */
    #[ORM\OneToMany(targetEntity: Track::class, mappedBy: 'section')]
    private Collection $tracks;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->tracks = new ArrayCollection();
    }

    /** @return Collection<int, Track> */
    public function getTracks(): Collection
    {
        return $this->tracks;
    }
}
