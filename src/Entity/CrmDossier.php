<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmRefund;
use App\Enum\CrmSituation;
use App\Repository\CrmDossierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A candidate's dossier in one formation of one year, from the pre-enrolment to a complete file.
 *
 * Where it stands (its stage, « Complet ») is never stored: App\Service\Crm\CrmDossierProgress
 * reads it from the pieces entered and the formation's settings each time. Every write goes
 * through App\Service\Crm\CrmDossierWriter, which also keeps the journal.
 */
#[ORM\Entity(repositoryClass: CrmDossierRepository::class)]
#[ORM\Table(name: 'crm_dossier')]
#[ORM\Index(name: 'idx_crm_dossier_program_situation', columns: ['program_id', 'situation'])]
class CrmDossier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CrmCandidate::class, inversedBy: 'dossiers', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private CrmCandidate $candidate;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Program $program;

    /** One of the formation's Formatool options, when the formation asks for it at pre-enrolment. */
    #[ORM\ManyToOne(targetEntity: Option::class)]
    #[ORM\JoinColumn(name: 'option_id', nullable: true, onDelete: 'SET NULL')]
    private ?Option $option = null;

    #[ORM\Column(length: 20, enumType: CrmPath::class)]
    private CrmPath $path;

    #[ORM\Column(length: 20, enumType: CrmModality::class)]
    private CrmModality $modality;

    #[ORM\Column(length: 20, enumType: CrmSituation::class)]
    private CrmSituation $situation = CrmSituation::Active;

    /** @var Collection<int, CrmOrigin> */
    #[ORM\ManyToMany(targetEntity: CrmOrigin::class)]
    #[ORM\JoinTable(name: 'crm_dossier_origin')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $origins;

    /** What the candidate did before (« Bac STMG », « BTS CG 2e année »). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $priorSchooling = null;

    /** An apprentice starting under SFP status, the contract not signed yet. */
    #[ORM\Column]
    private bool $sfp = false;

    #[ORM\Column(length: 10, nullable: true, enumType: CrmRefund::class)]
    private ?CrmRefund $refund = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $openedOn;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /** @var Collection<int, CrmDossierPiece> */
    #[ORM\OneToMany(targetEntity: CrmDossierPiece::class, mappedBy: 'dossier', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $pieces;

    /** @var Collection<int, CrmJournalEntry> */
    #[ORM\OneToMany(targetEntity: CrmJournalEntry::class, mappedBy: 'dossier', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['occurredOn' => 'ASC', 'id' => 'ASC'])]
    private Collection $journal;

    /** @var Collection<int, CrmModalityChange> */
    #[ORM\OneToMany(targetEntity: CrmModalityChange::class, mappedBy: 'dossier', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['effectiveOn' => 'ASC', 'id' => 'ASC'])]
    private Collection $modalityChanges;

    public function __construct(CrmCandidate $candidate, Program $program, CrmPath $path, CrmModality $modality, \DateTimeImmutable $openedOn, ?User $createdBy)
    {
        $this->candidate = $candidate;
        $this->program = $program;
        $this->path = $path;
        $this->modality = $modality;
        $this->openedOn = $openedOn;
        $this->createdAt = new \DateTimeImmutable();
        $this->createdBy = $createdBy;
        $this->origins = new ArrayCollection();
        $this->pieces = new ArrayCollection();
        $this->journal = new ArrayCollection();
        $this->modalityChanges = new ArrayCollection();
        $candidate->getDossiers()->add($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCandidate(): CrmCandidate
    {
        return $this->candidate;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    public function setProgram(Program $program): void
    {
        $this->program = $program;
    }

    public function getOption(): ?Option
    {
        return $this->option;
    }

    public function setOption(?Option $option): void
    {
        $this->option = $option;
    }

    public function getPath(): CrmPath
    {
        return $this->path;
    }

    public function setPath(CrmPath $path): void
    {
        $this->path = $path;
    }

    public function getModality(): CrmModality
    {
        return $this->modality;
    }

    public function setModality(CrmModality $modality): void
    {
        $this->modality = $modality;
    }

    public function getSituation(): CrmSituation
    {
        return $this->situation;
    }

    public function setSituation(CrmSituation $situation): void
    {
        $this->situation = $situation;
    }

    /** @return Collection<int, CrmOrigin> */
    public function getOrigins(): Collection
    {
        return $this->origins;
    }

    /** @param list<CrmOrigin> $origins */
    public function replaceOrigins(array $origins): void
    {
        $this->origins->clear();
        foreach ($origins as $origin) {
            if (!$this->origins->contains($origin)) {
                $this->origins->add($origin);
            }
        }
    }

    /** « JPO, Parcoursup », in the list's order; empty when none is known. */
    public function originText(): string
    {
        $origins = $this->origins->toArray();
        usort($origins, static fn (CrmOrigin $a, CrmOrigin $b): int => $a->getPosition() <=> $b->getPosition());

        return implode(', ', array_map(static fn (CrmOrigin $o): string => $o->getLabel(), $origins));
    }

    public function getPriorSchooling(): ?string
    {
        return $this->priorSchooling;
    }

    public function setPriorSchooling(?string $priorSchooling): void
    {
        $this->priorSchooling = null === $priorSchooling || '' === trim($priorSchooling) ? null : trim($priorSchooling);
    }

    public function isSfp(): bool
    {
        return $this->sfp;
    }

    public function setSfp(bool $sfp): void
    {
        $this->sfp = $sfp;
    }

    public function getRefund(): ?CrmRefund
    {
        return $this->refund;
    }

    public function setRefund(?CrmRefund $refund): void
    {
        $this->refund = $refund;
    }

    public function getOpenedOn(): \DateTimeImmutable
    {
        return $this->openedOn;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    /** @return Collection<int, CrmDossierPiece> */
    public function getPieces(): Collection
    {
        return $this->pieces;
    }

    public function entryFor(CrmPiece $piece): ?CrmDossierPiece
    {
        foreach ($this->pieces as $entry) {
            if ($entry->getPiece() === $piece) {
                return $entry;
            }
        }

        return null;
    }

    public function addEntry(CrmDossierPiece $entry): void
    {
        $this->pieces->add($entry);
    }

    public function removeEntry(CrmDossierPiece $entry): void
    {
        $this->pieces->removeElement($entry);
    }

    /** @return Collection<int, CrmJournalEntry> */
    public function getJournal(): Collection
    {
        return $this->journal;
    }

    public function addJournalEntry(CrmJournalEntry $entry): void
    {
        $this->journal->add($entry);
    }

    /** @return Collection<int, CrmModalityChange> */
    public function getModalityChanges(): Collection
    {
        return $this->modalityChanges;
    }

    public function addModalityChange(CrmModalityChange $change): void
    {
        $this->modalityChanges->add($change);
    }
}
