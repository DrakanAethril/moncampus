<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CrmCandidateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A person applying, with their contact details - valid for all their dossiers. The CRM never
 * attaches a new dossier to an existing candidate by itself (no matching on the mail or the name):
 * every dossier the team opens starts a candidate of its own. It is not a Formatool account.
 */
#[ORM\Entity(repositoryClass: CrmCandidateRepository::class)]
#[ORM\Table(name: 'crm_candidate')]
#[ORM\Index(name: 'idx_crm_candidate_email', columns: ['email'])]
class CrmCandidate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Upper case, as the team writes it. */
    #[ORM\Column(length: 120)]
    private string $lastName;

    #[ORM\Column(length: 120)]
    private string $firstName;

    /** Optional; lower case. A mail serves one dossier per formation (App\Service\Crm\CrmCandidateMail). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    /** A foreign student, subject to a visa: the dossier then asks for the attestation and the visa. */
    #[ORM\Column]
    private bool $foreignStudent = false;

    /**
     * Up to two legal guardians, nothing about them required.
     *
     * @var list<array{lastName: string, firstName: string, email: string, phone: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $guardians = [];

    /** @var Collection<int, CrmDossier> */
    #[ORM\OneToMany(targetEntity: CrmDossier::class, mappedBy: 'candidate')]
    private Collection $dossiers;

    public function __construct(string $lastName, string $firstName)
    {
        $this->lastName = $lastName;
        $this->firstName = $firstName;
        $this->dossiers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
    }

    /** « DUPONT Léa », the order the team reads and sorts by. */
    public function getFullName(): string
    {
        return $this->lastName.' '.$this->firstName;
    }

    public function getInitials(): string
    {
        return mb_substr($this->lastName, 0, 1).mb_substr($this->firstName, 0, 1);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = null === $email || '' === trim($email) ? null : mb_strtolower(trim($email));
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = null === $phone || '' === trim($phone) ? null : trim($phone);
    }

    public function isForeignStudent(): bool
    {
        return $this->foreignStudent;
    }

    public function setForeignStudent(bool $foreignStudent): void
    {
        $this->foreignStudent = $foreignStudent;
    }

    /** @return list<array{lastName: string, firstName: string, email: string, phone: string}> */
    public function getGuardians(): array
    {
        return $this->guardians;
    }

    /** @param list<array{lastName: string, firstName: string, email: string, phone: string}> $guardians */
    public function setGuardians(array $guardians): void
    {
        $this->guardians = \array_slice($guardians, 0, 2);
    }

    /** @return Collection<int, CrmDossier> */
    public function getDossiers(): Collection
    {
        return $this->dossiers;
    }
}
