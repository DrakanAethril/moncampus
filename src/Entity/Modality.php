<?php

namespace App\Entity;

use App\Repository\ModalityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * How students follow a Formation (e.g. alternance, initial), across one or more Formations.
 */
#[ORM\Entity(repositoryClass: ModalityRepository::class)]
#[ORM\Table(name: 'modality')]
class Modality extends AbstractStructureNode
{
    /** @var Collection<int, Formation> */
    #[ORM\ManyToMany(targetEntity: Formation::class, inversedBy: 'modalities')]
    #[ORM\JoinTable(name: 'modality_formation')]
    private Collection $formations;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->formations = new ArrayCollection();
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
            $formation->getModalities()->add($this);
        }

        return $this;
    }

    public function removeFormation(Formation $formation): static
    {
        if ($this->formations->removeElement($formation)) {
            $formation->getModalities()->removeElement($this);
        }

        return $this;
    }
}
