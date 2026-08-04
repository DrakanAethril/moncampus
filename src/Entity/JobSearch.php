<?php

namespace App\Entity;

use App\Repository\JobSearchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'état de la recherche d'un élève : ouverte, ou close par un enseignant via « Marquer terminé »
 * (design_handoff_stage_alternance, écran 1a).
 *
 * Clore une recherche a trois effets, énoncés par le handoff : la boîte **reste consultable**,
 * l'envoi est désactivé, et l'élève **sort des rappels**. Ce n'est donc pas une suppression ni un
 * archivage — d'où une entité dédiée plutôt qu'un drapeau sur l'élève : on veut savoir *qui* a
 * clos et *quand*, une clôture par erreur devant pouvoir être expliquée puis annulée.
 *
 * Une ligne n'existe que pour les élèves dont la recherche a été close : l'absence de ligne est
 * l'état normal.
 */
#[ORM\Entity(repositoryClass: JobSearchRepository::class)]
#[ORM\Table(name: 'job_search')]
#[ORM\UniqueConstraint(name: 'uniq_job_search_student', columns: ['student_id'])]
class JobSearch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $closedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'closed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $closedBy = null;

    public function __construct()
    {
        $this->closedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function setStudent(?User $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getClosedAt(): \DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getClosedBy(): ?User
    {
        return $this->closedBy;
    }

    public function setClosedBy(?User $closedBy): static
    {
        $this->closedBy = $closedBy;

        return $this;
    }
}
