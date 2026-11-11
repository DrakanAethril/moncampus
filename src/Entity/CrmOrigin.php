<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CrmOriginRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where a candidate heard of the formation (JPO, Parcoursup, a salon…), one of a list the team
 * keeps in the CRM settings, in its order. A dossier carries several. Renaming one renames it in
 * every dossier, which is why dossiers point at the row rather than copy its label; one that a
 * dossier carries is never deleted.
 */
#[ORM\Entity(repositoryClass: CrmOriginRepository::class)]
#[ORM\Table(name: 'crm_origin')]
#[ORM\UniqueConstraint(name: 'uniq_crm_origin_label', columns: ['label'])]
class CrmOrigin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column]
    private int $position;

    public function __construct(string $label, int $position)
    {
        $this->label = $label;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
