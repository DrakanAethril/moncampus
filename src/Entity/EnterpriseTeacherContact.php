<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EnterpriseTeacherContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Je connais bien cette entreprise » - a teacher who can open a door there, and why
 * (design/validated/vivier-entreprises.md, D8). Read by teachers and the administration, **never by
 * a student**. The teacher declares themselves and withdraws themselves; an administrator may
 * withdraw anybody's.
 */
#[ORM\Entity(repositoryClass: EnterpriseTeacherContactRepository::class)]
#[ORM\Table(name: 'enterprise_teacher_contact')]
#[ORM\UniqueConstraint(name: 'uniq_enterprise_teacher_contact', columns: ['enterprise_id', 'teacher_id'])]
class EnterpriseTeacherContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Enterprise::class)]
    #[ORM\JoinColumn(name: 'enterprise_id', nullable: false, onDelete: 'CASCADE')]
    private ?Enterprise $enterprise = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $teacher = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Enterprise $enterprise, User $teacher)
    {
        $this->enterprise = $enterprise;
        $this->teacher = $teacher;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnterprise(): ?Enterprise
    {
        return $this->enterprise;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $note = null !== $note ? trim($note) : null;
        $this->note = '' !== $note ? $note : null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
