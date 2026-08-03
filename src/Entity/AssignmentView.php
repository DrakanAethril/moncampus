<?php

namespace App\Entity;

use App\Repository\AssignmentViewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La consultation d'un travail par un étudiant : la trace que la page du travail - sa consigne et
 * ses documents - a été ouverte.
 *
 * C'est ce qui alimente le « ouvert par 19 / 24 » du cahier de texte, en remplacement de la
 * déclaration « marquer comme fait », qui restait une parole d'étudiant. Une ouverture n'est pas
 * une preuve de lecture, mais c'est un fait observé, daté, et que l'étudiant ne choisit pas de
 * produire - c'est ce qui la rend plus fiable.
 *
 * Une ligne par (travail, étudiant), écrite à la première ouverture puis seulement mise à jour :
 * la première date dit quand l'étudiant a pris connaissance du travail, la dernière quand il y est
 * revenu, et le compteur combien de fois.
 */
#[ORM\Entity(repositoryClass: AssignmentViewRepository::class)]
#[ORM\Table(name: 'assignment_view')]
#[ORM\UniqueConstraint(name: 'uniq_assignment_view', columns: ['assignment_id', 'student_id'])]
class AssignmentView
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

    #[ORM\Column(name: 'first_viewed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstViewedAt;

    #[ORM\Column(name: 'last_viewed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastViewedAt;

    #[ORM\Column(name: 'view_count')]
    private int $viewCount = 1;

    public function __construct(Assignment $assignment, User $student)
    {
        $this->assignment = $assignment;
        $this->student = $student;
        $this->firstViewedAt = new \DateTimeImmutable();
        $this->lastViewedAt = $this->firstViewedAt;
    }

    public function registerView(): static
    {
        $this->lastViewedAt = new \DateTimeImmutable();
        ++$this->viewCount;

        return $this;
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

    public function getFirstViewedAt(): \DateTimeImmutable
    {
        return $this->firstViewedAt;
    }

    public function getLastViewedAt(): \DateTimeImmutable
    {
        return $this->lastViewedAt;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }
}
