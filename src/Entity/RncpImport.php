<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RncpImportState;
use App\Repository\RncpImportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * « Récupérer chez France compétences » - one request to read one fiche (design/validated/portfolio.md §8).
 *
 * The click writes this row and nothing else. `app:rncp:fetch`, scheduled every minute, finds the
 * day's export through the data.gouv.fr API, reads it as a stream, keeps the requested fiche and
 * writes it into `payload`. The screen polls every two seconds. The administrator then maps the
 * options and the blocks' roles and confirms: France compétences proposes, the administrator
 * decides (R14).
 */
#[ORM\Entity(repositoryClass: RncpImportRepository::class)]
#[ORM\Table(name: 'rncp_import')]
#[ORM\Index(name: 'rncp_import_state_idx', columns: ['state', 'requested_at'])]
class RncpImport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'rncp_code', length: 20)]
    private string $rncpCode;

    #[ORM\Column(length: 20, enumType: RncpImportState::class)]
    private RncpImportState $state = RncpImportState::Pending;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', nullable: false)]
    private ?User $requestedBy = null;

    #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** The export read - `export-fiches-rncp-v4-1-2026-09-29.zip`. */
    #[ORM\Column(name: 'source_file', length: 255, nullable: true)]
    private ?string $sourceFile = null;

    #[ORM\Column(name: 'source_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sourceDate = null;

    /** @var array<string, mixed> the fiche as App\Service\Rncp\RncpFicheReader read it */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\ManyToOne(targetEntity: Referential::class)]
    #[ORM\JoinColumn(name: 'referential_id', nullable: true, onDelete: 'SET NULL')]
    private ?Referential $referential = null;

    #[ORM\Column(name: 'applied_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    public function __construct(string $rncpCode, User $requestedBy)
    {
        $this->rncpCode = strtoupper(trim($rncpCode));
        $this->requestedBy = $requestedBy;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRncpCode(): string
    {
        return $this->rncpCode;
    }

    public function getState(): RncpImportState
    {
        return $this->state;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getSourceFile(): ?string
    {
        return $this->sourceFile;
    }

    public function getSourceDate(): ?\DateTimeImmutable
    {
        return $this->sourceDate;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getReferential(): ?Referential
    {
        return $this->referential;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function start(): void
    {
        $this->state = RncpImportState::Running;
        $this->startedAt = new \DateTimeImmutable();
        $this->error = null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function succeed(array $payload, string $sourceFile, ?\DateTimeImmutable $sourceDate): void
    {
        $this->state = RncpImportState::Ready;
        $this->payload = $payload;
        $this->sourceFile = $sourceFile;
        $this->sourceDate = $sourceDate;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->state = RncpImportState::Failed;
        $this->error = $error;
        $this->finishedAt = new \DateTimeImmutable();
    }

    /** « Relancer » - back to the queue, the previous error kept until the next pass. */
    public function retry(): void
    {
        $this->state = RncpImportState::Pending;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function apply(Referential $referential): void
    {
        $this->state = RncpImportState::Applied;
        $this->referential = $referential;
        $this->appliedAt = new \DateTimeImmutable();
    }
}
