<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// Base shape for the teaching-sequence library's free-text tag entities (LibraryNiveauTag/
// LibraryOptionTag/LibraryBlocTag) - see design/validated/teaching-sequence-library.md. Each
// teacher builds their own private vocabulary per facet (no cross-teacher sharing, no relation to
// the real Cohort/Option/Bloc entities): typing a label that doesn't match one of their existing
// tags creates a new one, via App\Service\LibraryTagResolver, so a teacher can tag content
// against a Niveau/Option/Bloc that doesn't (or doesn't yet) officially exist elsewhere in the app.
#[ORM\MappedSuperclass]
abstract class AbstractLibraryTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    protected string $label;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    protected User $teacher;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    protected \DateTimeImmutable $creationDate;

    public function __construct(User $teacher, string $label)
    {
        $this->teacher = $teacher;
        $this->label = $label;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }
}
