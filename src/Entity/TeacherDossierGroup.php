<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeacherDossierGroupRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A heading over some pieces of a dossier intervenant — « Identité », « Statut et paiement ».
 *
 * A title and a rank, nothing else, exactly like App\Entity\DossierGroup: moving a piece from one
 * group to another never changes what is asked.
 */
#[ORM\Entity(repositoryClass: TeacherDossierGroupRepository::class)]
#[ORM\Table(name: 'teacher_dossier_group')]
class TeacherDossierGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TeacherDossier::class, inversedBy: 'groups')]
    #[ORM\JoinColumn(name: 'dossier_id', nullable: false, onDelete: 'CASCADE')]
    private ?TeacherDossier $dossier = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    #[ORM\Column]
    private int $position = 0;

    public function __construct(TeacherDossier $dossier, string $title, int $position = 0)
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

    public function getDossier(): ?TeacherDossier
    {
        return $this->dossier;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
