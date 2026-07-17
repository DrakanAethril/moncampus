<?php

namespace App\Entity;

use App\Enum\EcoParcoursStatus;
use App\Repository\EcoParcoursRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A teacher's reusable orienteering route - see design/design_campus_manager/README.md, "e-CO"
 * section, and reference/e-CO.dc.html screens 1d/1e. Owned by a teacher (ROLE_ECO), not a Program,
 * same reasoning as QuizTemplate. Always has exactly one Start and one Finish checkpoint
 * (App\Service\EcoParcoursFactory adds both at creation, alongside the requested number of
 * regular checkpoints) plus zero or more numbered ones in between - getStatus() below reflects
 * whether every checkpoint has been located yet from the mobile app.
 */
#[ORM\Entity(repositoryClass: EcoParcoursRepository::class)]
#[ORM\Table(name: 'eco_parcours')]
class EcoParcours
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    private ?User $teacher = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    /** @var Collection<int, EcoCheckpoint> */
    #[ORM\OneToMany(mappedBy: 'parcours', targetEntity: EcoCheckpoint::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $checkpoints;

    /** @var Collection<int, EcoCourse> */
    #[ORM\OneToMany(mappedBy: 'parcours', targetEntity: EcoCourse::class)]
    private Collection $courses;

    public function __construct(User $teacher)
    {
        $this->teacher = $teacher;
        $this->checkpoints = new ArrayCollection();
        $this->courses = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
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

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    /** @return Collection<int, EcoCheckpoint> */
    public function getCheckpoints(): Collection
    {
        return $this->checkpoints;
    }

    public function addCheckpoint(EcoCheckpoint $checkpoint): static
    {
        if (!$this->checkpoints->contains($checkpoint)) {
            $this->checkpoints->add($checkpoint);
        }

        return $this;
    }

    public function removeCheckpoint(EcoCheckpoint $checkpoint): static
    {
        $this->checkpoints->removeElement($checkpoint);

        return $this;
    }

    // Regular (numbered) checkpoints only - excludes the auto-created Start/Finish, e.g. for the
    // "8 + D/A" count shown on 1d.
    /** @return list<EcoCheckpoint> */
    public function getRegularCheckpoints(): array
    {
        return array_values(array_filter(
            $this->checkpoints->toArray(),
            static fn (EcoCheckpoint $checkpoint): bool => $checkpoint->getType() === \App\Enum\EcoCheckpointType::Checkpoint,
        ));
    }

    /** @return Collection<int, EcoCourse> */
    public function getCourses(): Collection
    {
        return $this->courses;
    }

    // Draft (nothing located yet) / ToLocate (some but not all) / Ready (every checkpoint has
    // GPS coordinates) - see EcoParcoursStatus's own docblock for why this isn't a stored column.
    public function getStatus(): EcoParcoursStatus
    {
        $total = $this->checkpoints->count();
        if (0 === $total) {
            return EcoParcoursStatus::Draft;
        }

        $locatedCount = \count(array_filter(
            $this->checkpoints->toArray(),
            static fn (EcoCheckpoint $checkpoint): bool => $checkpoint->isLocated(),
        ));

        if (0 === $locatedCount) {
            return EcoParcoursStatus::Draft;
        }

        return $locatedCount === $total ? EcoParcoursStatus::Ready : EcoParcoursStatus::ToLocate;
    }

    public function getLocatedCheckpointCount(): int
    {
        return \count(array_filter(
            $this->checkpoints->toArray(),
            static fn (EcoCheckpoint $checkpoint): bool => $checkpoint->isLocated(),
        ));
    }

    // Only a Ready parcours can have courses created against it (screen 1d: "Courses" is greyed
    // out otherwise) - see App\Security\Voter\EcoParcoursVoter/App\Controller\EcoCourseController.
    public function isReady(): bool
    {
        return EcoParcoursStatus::Ready === $this->getStatus();
    }
}
