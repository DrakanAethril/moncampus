<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JobboardBatchRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One accepted `POST .../offers` call, kept so that sending it again changes nothing.
 *
 * The data would already be idempotent without this - `premiere_vue` does not move, `derniere_vue`
 * moving is harmless - but **the tally would count twice**, and a batch report nobody can trust is
 * how a veille stops being read. So the agent stamps each call with an `Idempotency-Key`, and a key
 * already seen replays the answer that was given the first time.
 *
 * The payload hash is stored alongside: the same key with a different body is a client bug, and
 * answering it with someone else's result would be worse than refusing it.
 */
#[ORM\Entity(repositoryClass: JobboardBatchRequestRepository::class)]
#[ORM\Table(name: 'jobboard_batch_request')]
#[ORM\UniqueConstraint(name: 'uniq_jobboard_batch_request_key', columns: ['batch_id', 'idempotency_key'])]
class JobboardBatchRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: JobboardBatch::class)]
    #[ORM\JoinColumn(name: 'batch_id', nullable: false, onDelete: 'CASCADE')]
    private JobboardBatch $batch;

    #[ORM\Column(name: 'idempotency_key', length: 190)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'payload_hash', length: 64)]
    private string $payloadHash;

    /** @var array<array-key, mixed> */
    #[ORM\Column(name: 'response', type: Types::JSON)]
    private array $response;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<array-key, mixed> $response */
    public function __construct(JobboardBatch $batch, string $idempotencyKey, string $payloadHash, array $response)
    {
        $this->batch = $batch;
        $this->idempotencyKey = $idempotencyKey;
        $this->payloadHash = $payloadHash;
        $this->response = $response;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatch(): JobboardBatch
    {
        return $this->batch;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getPayloadHash(): string
    {
        return $this->payloadHash;
    }

    /** @return array<array-key, mixed> */
    public function getResponse(): array
    {
        return $this->response;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
