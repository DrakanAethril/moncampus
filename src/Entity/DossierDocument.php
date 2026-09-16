<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DossierDepositType;
use App\Enum\DossierValidationProfile;
use App\Repository\DossierDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One piece asked of every cible of the dossier.
 *
 * The three dates-and-switches it carries are the whole of the rule a cible reads under its name -
 * « Visible 1 sept. · rendu 15 oct. · dépassement bloqué · fichier » - and they are read in exactly
 * one place, App\Service\Dossier\DossierStatusResolver, so the screen and the refusal can never
 * disagree:
 *
 * - **$visibleFrom** decides existence, not merely display: before it, the cible cannot see the
 *   document at all and no route accepts a dépôt on it.
 * - **$dueOn** decides lateness. On its own it changes nothing about what is accepted.
 * - **$lateAllowed** is what decides that: false closes the dépôt once $dueOn has passed, true
 *   leaves it open and lets the row simply read « En retard ».
 *
 * A **correction requested reopens the dépôt whatever $dueOn says**, when the document allows
 * dépassement - asking for a correction the cible cannot then deposit is asking for nothing.
 */
#[ORM\Entity(repositoryClass: DossierDocumentRepository::class)]
#[ORM\Table(name: 'dossier_document')]
class DossierDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dossier::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(name: 'dossier_id', nullable: false, onDelete: 'CASCADE')]
    private ?Dossier $dossier = null;

    // Null is « Hors groupe » and is not an error state: SET NULL rather than CASCADE, so deleting
    // a group leaves its documents in the dossier.
    #[ORM\ManyToOne(targetEntity: DossierGroup::class)]
    #[ORM\JoinColumn(name: 'dossier_group_id', nullable: true, onDelete: 'SET NULL')]
    private ?DossierGroup $group = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(name: 'is_required')]
    private bool $required = true;

    #[ORM\Column(name: 'deposit_type', length: 20, enumType: DossierDepositType::class)]
    private DossierDepositType $depositType = DossierDepositType::Upload;

    #[ORM\Column(name: 'visible_from', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $visibleFrom = null;

    #[ORM\Column(name: 'due_on', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(propertyPath: 'visibleFrom', message: 'dossierDocumentDueBeforeVisibleMessage')]
    private ?\DateTimeImmutable $dueOn = null;

    #[ORM\Column(name: 'late_allowed')]
    private bool $lateAllowed = false;

    #[ORM\Column(name: 'validation_profile', length: 20, enumType: DossierValidationProfile::class)]
    private DossierValidationProfile $validationProfile = DossierValidationProfile::Deposit;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(Dossier $dossier)
    {
        $this->dossier = $dossier;
        $this->position = $dossier->nextDocumentPosition();

        $dossier->addDocument($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): ?Dossier
    {
        return $this->dossier;
    }

    public function getGroup(): ?DossierGroup
    {
        return $this->group;
    }

    public function setGroup(?DossierGroup $group): static
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

    public function getVisibleFrom(): ?\DateTimeImmutable
    {
        return $this->visibleFrom;
    }

    public function setVisibleFrom(?\DateTimeImmutable $visibleFrom): static
    {
        $this->visibleFrom = $visibleFrom;

        return $this;
    }

    public function getDueOn(): ?\DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function setDueOn(?\DateTimeImmutable $dueOn): static
    {
        $this->dueOn = $dueOn;

        return $this;
    }

    public function isLateAllowed(): bool
    {
        return $this->lateAllowed;
    }

    public function setLateAllowed(bool $lateAllowed): static
    {
        $this->lateAllowed = $lateAllowed;

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

    public function needsValidation(): bool
    {
        return DossierValidationProfile::Validation === $this->validationProfile;
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

    /** Has the document's visibility date arrived? Before it, the document does not exist for a cible. */
    public function isVisibleOn(\DateTimeImmutable $day): bool
    {
        return null !== $this->visibleFrom && $this->visibleFrom->format('Y-m-d') <= $day->format('Y-m-d');
    }

    public function isOverdueOn(\DateTimeImmutable $day): bool
    {
        return null !== $this->dueOn && $this->dueOn->format('Y-m-d') < $day->format('Y-m-d');
    }
}
