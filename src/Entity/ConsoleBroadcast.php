<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConsoleBroadcastRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One command, sent to every machine of one batch at once.
 *
 * **The only thing done in a console that has an effect somewhere else**, which is the whole reason
 * it has a row of its own rather than living in the session's transcript: a transcript answers
 * « what happened on this machine », and this answers « what went out from here ».
 *
 * `command` is the visible text and not the bytes: a broadcast is a *line*, not a keystroke
 * sequence. One does not broadcast an interactive session - half the machines would be at a
 * different prompt - one broadcasts a command.
 *
 * `results` holds one entry per machine, refused ones included and named. A switched-off machine is
 * not a failure of the broadcast: it is a switched-off machine, and the banner says so with its
 * name rather than collapsing to « 7 / 8 ».
 *
 * @phpstan-type BroadcastResult array{vmid: int, name: string, ok: bool, message?: string}
 */
#[ORM\Entity(repositoryClass: ConsoleBroadcastRepository::class)]
#[ORM\Table(name: 'console_broadcast')]
#[ORM\Index(name: 'idx_console_broadcast_session', columns: ['session_id'])]
class ConsoleBroadcast
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ConsoleSession::class)]
    #[ORM\JoinColumn(name: 'session_id', nullable: false, onDelete: 'CASCADE')]
    private ?ConsoleSession $session = null;

    #[ORM\ManyToOne(targetEntity: VmBatch::class)]
    #[ORM\JoinColumn(name: 'batch_id', nullable: true, onDelete: 'SET NULL')]
    private ?VmBatch $batch = null;

    /** Frozen beside the relation, so a broadcast still reads after its batch is gone. */
    #[ORM\Column(name: 'batch_label', length: 120, nullable: true)]
    private ?string $batchLabel = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $command;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'sent_by_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $sentBy = null;

    /**
     * One entry per machine of the batch.
     *
     * @var list<BroadcastResult>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $results = [];

    public function __construct(ConsoleSession $session, ?VmBatch $batch, string $command, User $sentBy)
    {
        $this->session = $session;
        $this->batch = $batch;
        $this->batchLabel = $batch?->getLabel();
        $this->command = $command;
        $this->sentBy = $sentBy;
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?ConsoleSession
    {
        return $this->session;
    }

    public function getBatch(): ?VmBatch
    {
        return $this->batch;
    }

    public function getBatchLabel(): ?string
    {
        return $this->batchLabel;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getSentBy(): ?User
    {
        return $this->sentBy;
    }

    /** @return list<BroadcastResult> */
    public function getResults(): array
    {
        return $this->results;
    }

    /** @param list<BroadcastResult> $results */
    public function setResults(array $results): static
    {
        $this->results = $results;

        return $this;
    }

    public function countDone(): int
    {
        return \count(array_filter($this->results, static fn (array $result): bool => $result['ok']));
    }

    public function countRefused(): int
    {
        return \count($this->results) - $this->countDone();
    }
}
