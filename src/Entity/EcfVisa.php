<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EcfPart;
use App\Enum\EcfVisaSlot;
use App\Repository\EcfVisaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One signed « Visa » cell. A visa is the signature of the person who clicked: the printed name is
 * theirs and the printed « Signé numériquement le » date is the server's clock at the click, never
 * a date somebody typed. The « Date » cell next to it ($evaluatedOn) is the evaluation's date, which
 * the evaluator enters.
 *
 * Rows are only ever created and deleted (App\Service\Ecf\EcfSigner), never edited.
 */
#[ORM\Entity(repositoryClass: EcfVisaRepository::class)]
#[ORM\Table(name: 'ecf_visa')]
class EcfVisa
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcfBooklet::class, inversedBy: 'visas')]
    #[ORM\JoinColumn(name: 'booklet_id', nullable: false, onDelete: 'CASCADE')]
    private EcfBooklet $booklet;

    // Null for the synthesis.
    #[ORM\ManyToOne(targetEntity: EcfActivity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: true, onDelete: 'CASCADE')]
    private ?EcfActivity $activity;

    #[ORM\Column(length: 20, enumType: EcfPart::class)]
    private EcfPart $part;

    #[ORM\Column(length: 20, enumType: EcfVisaSlot::class)]
    private EcfVisaSlot $slot;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'signer_id', nullable: false)]
    private User $signer;

    // Printed in the « Nom » cell, copied at signature so a later change of name does not rewrite it.
    #[ORM\Column(name: 'signer_name', length: 120)]
    private string $signerName;

    #[ORM\Column(name: 'evaluated_on', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $evaluatedOn;

    #[ORM\Column(name: 'signed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $signedAt;

    public function __construct(EcfBooklet $booklet, ?EcfActivity $activity, EcfPart $part, EcfVisaSlot $slot, User $signer, string $signerName, \DateTimeImmutable $evaluatedOn, \DateTimeImmutable $signedAt)
    {
        $this->booklet = $booklet;
        $this->activity = $activity;
        $this->part = $part;
        $this->slot = $slot;
        $this->signer = $signer;
        $this->signerName = $signerName;
        $this->evaluatedOn = $evaluatedOn;
        $this->signedAt = $signedAt;
        $booklet->addVisa($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooklet(): EcfBooklet
    {
        return $this->booklet;
    }

    public function getActivity(): ?EcfActivity
    {
        return $this->activity;
    }

    public function getPart(): EcfPart
    {
        return $this->part;
    }

    public function getSlot(): EcfVisaSlot
    {
        return $this->slot;
    }

    public function getSigner(): User
    {
        return $this->signer;
    }

    public function getSignerName(): string
    {
        return $this->signerName;
    }

    public function getEvaluatedOn(): \DateTimeImmutable
    {
        return $this->evaluatedOn;
    }

    public function getSignedAt(): \DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function covers(?EcfActivity $activity, EcfPart $part): bool
    {
        return $this->part === $part && $this->activity === $activity;
    }
}
