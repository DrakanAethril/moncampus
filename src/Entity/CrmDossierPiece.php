<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CrmDossierPieceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A piece entered in a dossier: when, by whom, with which value.
 *
 * The value's shape follows the piece's kind: none for a box, the chosen text for a choice or a
 * list, the text typed, a whole number of euros, or `{"start": "2026-09-01", "end": "2028-08-31"}`
 * for a period. A row taken over from the team's spreadsheet may have no date: it is done, its
 * date unknown (`$doneOn` null and `$imported` true).
 */
#[ORM\Entity(repositoryClass: CrmDossierPieceRepository::class)]
#[ORM\Table(name: 'crm_dossier_piece')]
#[ORM\UniqueConstraint(name: 'uniq_crm_dossier_piece', columns: ['dossier_id', 'piece_id'])]
class CrmDossierPiece
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CrmDossier::class, inversedBy: 'pieces')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CrmDossier $dossier;

    #[ORM\ManyToOne(targetEntity: CrmPiece::class)]
    #[ORM\JoinColumn(nullable: false)]
    private CrmPiece $piece;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $doneOn;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private mixed $value;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $paymentMode = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $enteredBy;

    /** The name of whoever entered it, kept when the account goes. */
    #[ORM\Column(length: 160)]
    private string $enteredByName;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $enteredAt;

    #[ORM\Column]
    private bool $imported = false;

    public function __construct(CrmDossier $dossier, CrmPiece $piece, ?\DateTimeImmutable $doneOn, mixed $value, ?User $enteredBy, string $enteredByName)
    {
        $this->dossier = $dossier;
        $this->piece = $piece;
        $this->doneOn = $doneOn;
        $this->value = $value;
        $this->enteredBy = $enteredBy;
        $this->enteredByName = $enteredByName;
        $this->enteredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): CrmDossier
    {
        return $this->dossier;
    }

    public function getPiece(): CrmPiece
    {
        return $this->piece;
    }

    public function getDoneOn(): ?\DateTimeImmutable
    {
        return $this->doneOn;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getPaymentMode(): ?string
    {
        return $this->paymentMode;
    }

    public function setPaymentMode(?string $paymentMode): void
    {
        $this->paymentMode = null === $paymentMode || '' === $paymentMode ? null : $paymentMode;
    }

    public function getEnteredBy(): ?User
    {
        return $this->enteredBy;
    }

    public function getEnteredByName(): string
    {
        return $this->enteredByName;
    }

    public function getEnteredAt(): \DateTimeImmutable
    {
        return $this->enteredAt;
    }

    public function isImported(): bool
    {
        return $this->imported;
    }

    public function setImported(bool $imported): void
    {
        $this->imported = $imported;
    }

    /** A new value and date, entered by somebody else perhaps: the row is the latest entry. */
    public function rewrite(?\DateTimeImmutable $doneOn, mixed $value, ?User $enteredBy, string $enteredByName): void
    {
        $this->doneOn = $doneOn;
        $this->value = $value;
        $this->enteredBy = $enteredBy;
        $this->enteredByName = $enteredByName;
        $this->enteredAt = new \DateTimeImmutable();
        $this->imported = false;
    }
}
