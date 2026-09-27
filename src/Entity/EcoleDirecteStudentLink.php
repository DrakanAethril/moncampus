<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EcoleDirecteStudentLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Who a MonCampus student is in École Directe, when their two names differ - a second first name, a
 * married name, a typo on one side. Set by hand from the grade send's preview, never guessed, and
 * remembered for every later send.
 *
 * **One per student, for the whole establishment**: who a child is in École Directe is a fact about
 * the child, not about the teacher who first noticed it. And one per École Directe student, so that
 * two MonCampus students never send to the same child. The École Directe id is what matches; the
 * name École Directe gave is kept too, so that a student École Directe re-created under a new id
 * is still found by it (App\EcoleDirecte\EcoleDirecteGradePlanner).
 */
#[ORM\Entity(repositoryClass: EcoleDirecteStudentLinkRepository::class)]
#[ORM\Table(name: 'ecole_directe_student_link')]
#[ORM\UniqueConstraint(name: 'uniq_ecole_directe_student_link_student', columns: ['student_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ecole_directe_student_link_ecole_directe', columns: ['ecole_directe_id'])]
class EcoleDirecteStudentLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(name: 'ecole_directe_id')]
    private int $ecoleDirecteId;

    /** École Directe's surname, particle included. */
    #[ORM\Column(name: 'last_name', length: 150)]
    private string $lastName;

    #[ORM\Column(name: 'first_name', length: 150)]
    private string $firstName;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'linked_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $linkedBy;

    #[ORM\Column(name: 'linked_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $linkedAt;

    public function __construct(User $student, int $ecoleDirecteId, string $lastName, string $firstName, ?User $linkedBy, \DateTimeImmutable $now)
    {
        $this->student = $student;
        $this->ecoleDirecteId = $ecoleDirecteId;
        $this->lastName = $lastName;
        $this->firstName = $firstName;
        $this->linkedBy = $linkedBy;
        $this->linkedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): User
    {
        return $this->student;
    }

    public function getEcoleDirecteId(): int
    {
        return $this->ecoleDirecteId;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLinkedBy(): ?User
    {
        return $this->linkedBy;
    }

    public function getLinkedAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
    }

    public function relink(int $ecoleDirecteId, string $lastName, string $firstName, ?User $linkedBy, \DateTimeImmutable $now): static
    {
        $this->ecoleDirecteId = $ecoleDirecteId;
        $this->lastName = $lastName;
        $this->firstName = $firstName;
        $this->linkedBy = $linkedBy;
        $this->linkedAt = $now;

        return $this;
    }
}
