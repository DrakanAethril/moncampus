<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CrmModality;
use App\Repository\CrmModalityChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One step of a dossier's modality history: the first modality, then each change. */
#[ORM\Entity(repositoryClass: CrmModalityChangeRepository::class)]
#[ORM\Table(name: 'crm_modality_change')]
class CrmModalityChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CrmDossier::class, inversedBy: 'modalityChanges')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CrmDossier $dossier;

    #[ORM\Column(length: 20, enumType: CrmModality::class)]
    private CrmModality $modality;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $effectiveOn;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(length: 160)]
    private string $authorName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason;

    public function __construct(CrmDossier $dossier, CrmModality $modality, \DateTimeImmutable $effectiveOn, ?User $author, string $authorName, ?string $reason)
    {
        $this->dossier = $dossier;
        $this->modality = $modality;
        $this->effectiveOn = $effectiveOn;
        $this->author = $author;
        $this->authorName = $authorName;
        $this->reason = null === $reason || '' === trim($reason) ? null : trim($reason);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): CrmDossier
    {
        return $this->dossier;
    }

    public function getModality(): CrmModality
    {
        return $this->modality;
    }

    public function getEffectiveOn(): \DateTimeImmutable
    {
        return $this->effectiveOn;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
