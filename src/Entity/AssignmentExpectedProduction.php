<?php

namespace App\Entity;

use App\Enum\AssignmentProductionFormat;
use App\Repository\AssignmentExpectedProductionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une production attendue d'un travail de type Dépôt (design_handoff_creation_travail 2a) : ce que
 * l'étudiant doit rendre, sous quel format, et pour quand.
 *
 * L'échéance est facultative et déroge à celle du travail, comme la date de visibilité d'un
 * LessonLogAttachment déroge à celle de sa section : null = « échéance du travail », une date = le
 * fichier a la sienne, et le travail affiche alors un bandeau de rappel côté enseignant comme côté
 * étudiant. C'est ce qui permet d'annoncer un compte rendu pour la semaine prochaine et ses fichiers
 * de configuration pour ce soir sans créer deux travaux.
 */
#[ORM\Entity(repositoryClass: AssignmentExpectedProductionRepository::class)]
#[ORM\Table(name: 'assignment_expected_production')]
class AssignmentExpectedProduction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Assignment::class, inversedBy: 'expectedProductions')]
    #[ORM\JoinColumn(name: 'assignment_id', nullable: false, onDelete: 'CASCADE')]
    private ?Assignment $assignment = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(length: 20, enumType: AssignmentProductionFormat::class)]
    private AssignmentProductionFormat $format = AssignmentProductionFormat::Any;

    #[ORM\Column(name: 'due_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    // Rang d'affichage : les lignes se lisent dans l'ordre où l'enseignant les a posées.
    #[ORM\Column]
    private int $position = 0;

    /**
     * Le travail est facultatif au constructeur : les lignes ajoutées à la volée dans l'assistant
     * naissent sans porteur (CollectionType les instancie sans argument) et sont rattachées ensuite
     * par Assignment::addExpectedProduction().
     */
    public function __construct(?Assignment $assignment = null)
    {
        $this->assignment = $assignment;

        if (null !== $assignment && !$assignment->getExpectedProductions()->contains($this)) {
            $assignment->getExpectedProductions()->add($this);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssignment(): ?Assignment
    {
        return $this->assignment;
    }

    public function setAssignment(?Assignment $assignment): static
    {
        $this->assignment = $assignment;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getFormat(): AssignmentProductionFormat
    {
        return $this->format;
    }

    public function setFormat(AssignmentProductionFormat $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

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

    /** L'échéance qui s'applique réellement : la sienne, sinon celle du travail. */
    public function getEffectiveDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate ?? $this->assignment?->getDueDate();
    }

    public function hasOwnDueDate(): bool
    {
        return null !== $this->dueDate;
    }
}
