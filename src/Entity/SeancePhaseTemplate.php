<?php

namespace App\Entity;

use App\Repository\SeancePhaseTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// One phase of a SeanceTemplate's "déroulé" (e.g. "Accueil", "Réactivation", "Synthèse") -
// explicitly structured fields rather than one rich-text block, per
// design/validated/teaching-sequence-library.md.
#[ORM\Entity(repositoryClass: SeancePhaseTemplateRepository::class)]
#[ORM\Table(name: 'seance_phase_template')]
class SeancePhaseTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SeanceTemplate::class, inversedBy: 'seancePhaseTemplates')]
    #[ORM\JoinColumn(name: 'seance_template_id', nullable: false)]
    private ?SeanceTemplate $seanceTemplate = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $duree = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contenu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objectifs = null;

    // What the teacher does during this phase.
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $enseignant = null;

    // What the student does during this phase.
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $etudiant = null;

    #[ORM\Column(name: 'moyens_supports', type: Types::TEXT, nullable: true)]
    private ?string $moyensSupports = null;

    // Anticipated pitfalls.
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $difficultes = null;

    public function __construct(SeanceTemplate $seanceTemplate)
    {
        $this->seanceTemplate = $seanceTemplate;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeanceTemplate(): ?SeanceTemplate
    {
        return $this->seanceTemplate;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getDuree(): ?string
    {
        return $this->duree;
    }

    public function setDuree(?string $duree): static
    {
        $this->duree = $duree;

        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(?string $contenu): static
    {
        $this->contenu = $contenu;

        return $this;
    }

    public function getObjectifs(): ?string
    {
        return $this->objectifs;
    }

    public function setObjectifs(?string $objectifs): static
    {
        $this->objectifs = $objectifs;

        return $this;
    }

    public function getEnseignant(): ?string
    {
        return $this->enseignant;
    }

    public function setEnseignant(?string $enseignant): static
    {
        $this->enseignant = $enseignant;

        return $this;
    }

    public function getEtudiant(): ?string
    {
        return $this->etudiant;
    }

    public function setEtudiant(?string $etudiant): static
    {
        $this->etudiant = $etudiant;

        return $this;
    }

    public function getMoyensSupports(): ?string
    {
        return $this->moyensSupports;
    }

    public function setMoyensSupports(?string $moyensSupports): static
    {
        $this->moyensSupports = $moyensSupports;

        return $this;
    }

    public function getDifficultes(): ?string
    {
        return $this->difficultes;
    }

    public function setDifficultes(?string $difficultes): static
    {
        $this->difficultes = $difficultes;

        return $this;
    }
}
