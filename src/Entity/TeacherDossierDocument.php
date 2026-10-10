<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DossierDepositType;
use App\Enum\DossierValidationProfile;
use App\Repository\TeacherDossierDocumentRepository;
use App\Service\Dossier\RequestedPiece;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One piece asked of the intervenant.
 *
 * It answers the four questions of App\Service\Dossier\RequestedPiece, and two of the answers are
 * constants - which is the difference with a dossier documentaire's document:
 *
 * - **it is always visible.** A piece asked of an intervenant exists the day the dossier is handed
 *   to them; there is no « à venir »;
 * - **its date limite closes nothing.** $dueOn is optional, and once passed it makes the piece read
 *   « En retard » and nothing more: refusing a bank statement because it is late would only mean
 *   never getting it.
 *
 * On a model $dueOn is always null: the date is given when the model is turned into a dossier.
 */
#[ORM\Entity(repositoryClass: TeacherDossierDocumentRepository::class)]
#[ORM\Table(name: 'teacher_dossier_document')]
class TeacherDossierDocument implements RequestedPiece
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TeacherDossier::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(name: 'dossier_id', nullable: false, onDelete: 'CASCADE')]
    private ?TeacherDossier $dossier = null;

    // Null is « hors groupe ». SET NULL rather than CASCADE: a group is a heading, and removing a
    // heading must not take the pieces under it.
    #[ORM\ManyToOne(targetEntity: TeacherDossierGroup::class)]
    #[ORM\JoinColumn(name: 'dossier_group_id', nullable: true, onDelete: 'SET NULL')]
    private ?TeacherDossierGroup $group = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(name: 'is_required')]
    private bool $required = true;

    #[ORM\Column(name: 'deposit_type', length: 20, enumType: DossierDepositType::class)]
    private DossierDepositType $depositType = DossierDepositType::Upload;

    #[ORM\Column(name: 'validation_profile', length: 20, enumType: DossierValidationProfile::class)]
    private DossierValidationProfile $validationProfile = DossierValidationProfile::Deposit;

    #[ORM\Column(name: 'due_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueOn = null;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(TeacherDossier $dossier)
    {
        $this->dossier = $dossier;
        $this->position = $dossier->nextDocumentPosition();

        $dossier->addDocument($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): ?TeacherDossier
    {
        return $this->dossier;
    }

    public function getGroup(): ?TeacherDossierGroup
    {
        return $this->group;
    }

    public function setGroup(?TeacherDossierGroup $group): static
    {
        $this->group = $group;

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

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

        return $this;
    }

    public function getDepositType(): DossierDepositType
    {
        return $this->depositType;
    }

    public function setDepositType(DossierDepositType $depositType): static
    {
        $this->depositType = $depositType;

        return $this;
    }

    public function getValidationProfile(): DossierValidationProfile
    {
        return $this->validationProfile;
    }

    public function setValidationProfile(DossierValidationProfile $validationProfile): static
    {
        $this->validationProfile = $validationProfile;

        return $this;
    }

    public function getDueOn(): ?\DateTimeImmutable
    {
        return $this->dueOn;
    }

    /** A model's piece keeps no date, whatever is handed in: the date belongs to a dossier. */
    public function setDueOn(?\DateTimeImmutable $dueOn): static
    {
        $this->dueOn = true === $this->dossier?->isTemplate() ? null : $dueOn;

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

    #[\Override]
    public function isVisibleOn(\DateTimeImmutable $day): bool
    {
        return true;
    }

    #[\Override]
    public function isOverdueOn(\DateTimeImmutable $day): bool
    {
        return null !== $this->dueOn && $this->dueOn->format('Y-m-d') < $day->format('Y-m-d');
    }

    #[\Override]
    public function isLateAllowed(): bool
    {
        return true;
    }

    #[\Override]
    public function needsValidation(): bool
    {
        return DossierValidationProfile::Validation === $this->validationProfile;
    }
}
