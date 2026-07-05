<?php

namespace App\Entity;

use App\Repository\OptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A specialization students can follow across one or more Formations (e.g. SLAM, SISR, Latin).
 */
#[ORM\Entity(repositoryClass: OptionRepository::class)]
#[ORM\Table(name: '`option`')]
class Option extends AbstractStructureNode
{
    #[ORM\Column(name: 'short_name', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $shortName;

    /** @var Collection<int, Formation> */
    #[ORM\ManyToMany(targetEntity: Formation::class, inversedBy: 'options')]
    #[ORM\JoinTable(name: 'option_formation')]
    private Collection $formations;

    public function __construct(string $name, string $shortName)
    {
        parent::__construct($name);
        $this->shortName = $shortName;
        $this->formations = new ArrayCollection();
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

    /** @return Collection<int, Formation> */
    public function getFormations(): Collection
    {
        return $this->formations;
    }

    public function addFormation(Formation $formation): static
    {
        if (!$this->formations->contains($formation)) {
            $this->formations->add($formation);
            $formation->getOptions()->add($this);
        }

        return $this;
    }

    public function removeFormation(Formation $formation): static
    {
        if ($this->formations->removeElement($formation)) {
            $formation->getOptions()->removeElement($this);
        }

        return $this;
    }
}
