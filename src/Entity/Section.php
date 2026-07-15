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

    // Tabler icon name (e.g. "school", "building"), rendered next to this Section's entry in the
    // top nav - see assets/icons/tabler-icons-catalog.json for the full picker catalog and
    // assets/icons/tabler-sprite.svg for the matching <symbol id="tabler-icon-{name}"> defs.
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $icon = null;

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

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }
}
