<?php

namespace App\Entity;

use App\Repository\EcoTeamRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// A binôme/groupe within an EcoCourse that has teamsEnabled - purely a display grouping for
// results, doesn't change scan/scoring rules per runner.
#[ORM\Entity(repositoryClass: EcoTeamRepository::class)]
#[ORM\Table(name: 'eco_team')]
class EcoTeam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcoCourse::class, inversedBy: 'teams')]
    #[ORM\JoinColumn(name: 'course_id', nullable: false)]
    private ?EcoCourse $course = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    public function __construct(EcoCourse $course)
    {
        $this->course = $course;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCourse(): ?EcoCourse
    {
        return $this->course;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
