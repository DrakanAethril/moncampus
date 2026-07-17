<?php

namespace App\Entity;

use App\Enum\EcoScanMethod;
use App\Enum\EcoScanResult;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One scan/manual-code attempt at a checkpoint - every attempt is journalised, success or not
 * (see reference/e-CO.dc.html screen 1i's scan table: a failed then retried scan shows as two
 * rows). $distanceMeters is the runner-to-checkpoint distance computed at scan time, compared
 * against the checkpoint's own EcoCheckpoint::$toleranceMeters to produce $result.
 */
#[ORM\Entity(repositoryClass: \App\Repository\EcoCheckpointScanRepository::class)]
#[ORM\Table(name: 'eco_checkpoint_scan')]
class EcoCheckpointScan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcoRunner::class, inversedBy: 'scans')]
    #[ORM\JoinColumn(name: 'runner_id', nullable: false)]
    private ?EcoRunner $runner = null;

    #[ORM\ManyToOne(targetEntity: EcoCheckpoint::class)]
    #[ORM\JoinColumn(name: 'checkpoint_id', nullable: false)]
    private ?EcoCheckpoint $checkpoint = null;

    #[ORM\Column(name: 'scanned_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $scannedAt = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(name: 'distance_meters', type: Types::FLOAT, nullable: true)]
    private ?float $distanceMeters = null;

    #[ORM\Column(length: 20, enumType: EcoScanMethod::class)]
    private EcoScanMethod $method = EcoScanMethod::QrScan;

    #[ORM\Column(length: 20, enumType: EcoScanResult::class)]
    private EcoScanResult $result = EcoScanResult::Success;

    // 1 for a runner's first attempt at this checkpoint, 2+ for each retry after a failure - the
    // "(2e essai)" label on 1i.
    #[ORM\Column(name: 'attempt_sequence')]
    private int $attemptSequence = 1;

    public function __construct(EcoRunner $runner, EcoCheckpoint $checkpoint)
    {
        $this->runner = $runner;
        $this->checkpoint = $checkpoint;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRunner(): ?EcoRunner
    {
        return $this->runner;
    }

    public function getCheckpoint(): ?EcoCheckpoint
    {
        return $this->checkpoint;
    }

    public function getScannedAt(): ?\DateTimeImmutable
    {
        return $this->scannedAt;
    }

    public function setScannedAt(\DateTimeImmutable $scannedAt): static
    {
        $this->scannedAt = $scannedAt;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getDistanceMeters(): ?float
    {
        return $this->distanceMeters;
    }

    public function setDistanceMeters(?float $distanceMeters): static
    {
        $this->distanceMeters = $distanceMeters;

        return $this;
    }

    public function getMethod(): EcoScanMethod
    {
        return $this->method;
    }

    public function setMethod(EcoScanMethod $method): static
    {
        $this->method = $method;

        return $this;
    }

    public function getResult(): EcoScanResult
    {
        return $this->result;
    }

    public function setResult(EcoScanResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function getAttemptSequence(): int
    {
        return $this->attemptSequence;
    }

    public function setAttemptSequence(int $attemptSequence): static
    {
        $this->attemptSequence = $attemptSequence;

        return $this;
    }
}
