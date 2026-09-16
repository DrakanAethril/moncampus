<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DossierGroupRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A titled section of a dossier — « Rapport écrit », « Soutenance ».
 *
 * A title and an order, and nothing else on purpose: a group carries no date, no obligation and no
 * validation profile, so moving a document from one group to another never changes what is asked of
 * the cible. Deleting a group therefore does not delete its documents - they fall back under « Hors
 * groupe », the same way FileLibraryNode's folders were deliberately not allowed to take their
 * contents with them.
 */
#[ORM\Entity(repositoryClass: DossierGroupRepository::class)]
#[ORM\Table(name: 'dossier_group')]
class DossierGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dossier::class, inversedBy: 'groups')]
    #[ORM\JoinColumn(name: 'dossier_id', nullable: false, onDelete: 'CASCADE')]
    private ?Dossier $dossier = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    #[ORM\Column]
    private int $position = 0;

    public function __construct(Dossier $dossier, string $title, int $position = 0)
    {
        $this->dossier = $dossier;
        $this->title = $title;
        $this->position = $position;

        $dossier->addGroup($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): ?Dossier
    {
        return $this->dossier;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
