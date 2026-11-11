<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CrmJournalKind;
use App\Repository\CrmJournalEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A line of a dossier's journal: a call, a mail, a meeting, a note of the team, or what the CRM
 * itself wrote (a situation changed, a piece entered). Never rewritten: a line says what was
 * written, under the labels of the day.
 */
#[ORM\Entity(repositoryClass: CrmJournalEntryRepository::class)]
#[ORM\Table(name: 'crm_journal_entry')]
#[ORM\Index(name: 'idx_crm_journal_occurred', columns: ['occurred_on'])]
class CrmJournalEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CrmDossier::class, inversedBy: 'journal')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CrmDossier $dossier;

    #[ORM\Column(length: 20, enumType: CrmJournalKind::class)]
    private CrmJournalKind $kind;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $occurredOn;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(length: 160)]
    private string $authorName;

    /** The piece a « Saisie » line is about - what gives a piece its history. */
    #[ORM\ManyToOne(targetEntity: CrmPiece::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CrmPiece $piece = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(CrmDossier $dossier, CrmJournalKind $kind, string $text, \DateTimeImmutable $occurredOn, ?User $author, string $authorName, ?CrmPiece $piece = null)
    {
        $this->dossier = $dossier;
        $this->kind = $kind;
        $this->text = $text;
        $this->occurredOn = $occurredOn;
        $this->author = $author;
        $this->authorName = $authorName;
        $this->piece = $piece;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): CrmDossier
    {
        return $this->dossier;
    }

    public function getKind(): CrmJournalKind
    {
        return $this->kind;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getOccurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function getPiece(): ?CrmPiece
    {
        return $this->piece;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
