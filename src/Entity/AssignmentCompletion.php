<?php

namespace App\Entity;

use App\Repository\AssignmentCompletionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * « Marquer comme fait » : la déclaration d'un étudiant sur un travail qui n'attend ni dépôt ni
 * passation (maquette 4a). Une lecture, une révision, des exercices sur cahier n'ont pas d'autre
 * preuve d'achèvement que la parole de l'étudiant.
 *
 * Une ligne par (travail, étudiant), créée à la déclaration et supprimée si l'étudiant se dédit -
 * l'absence de ligne est donc « à faire », ce qui évite d'avoir à en écrire une pour chaque
 * étudiant à la création d'un travail. Le dépôt de fichier a sa propre trace, AssignmentSubmission.
 */
#[ORM\Entity(repositoryClass: AssignmentCompletionRepository::class)]
#[ORM\Table(name: 'assignment_completion')]
#[ORM\UniqueConstraint(name: 'uniq_assignment_completion', columns: ['assignment_id', 'student_id'])]
class AssignmentCompletion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Assignment::class)]
    #[ORM\JoinColumn(name: 'assignment_id', nullable: false, onDelete: 'CASCADE')]
    private ?Assignment $assignment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'done_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $doneAt;

    public function __construct(Assignment $assignment, User $student)
    {
        $this->assignment = $assignment;
        $this->student = $student;
        $this->doneAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssignment(): ?Assignment
    {
        return $this->assignment;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getDoneAt(): \DateTimeImmutable
    {
        return $this->doneAt;
    }
}
