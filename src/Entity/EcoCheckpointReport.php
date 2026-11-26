<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * « Balise introuvable » - a runner telling the platform that a flag is not where the parcours says
 * (taken down, never placed, its QR torn off). In a race run in order it is what lets them go on:
 * the flag is passed over for them, the next one becomes the one expected (EcoScanService). It
 * validates nothing - the run stays incomplete, and unranked, until the teacher neutralises the flag
 * for the whole race (EcoCourse::$neutralizedCheckpoints), which is the teacher's call, not theirs.
 *
 * One per runner and flag: reporting twice says nothing more.
 */
#[ORM\Entity]
#[ORM\Table(name: 'eco_checkpoint_report')]
#[ORM\UniqueConstraint(name: 'uniq_eco_checkpoint_report_runner_checkpoint', columns: ['runner_id', 'checkpoint_id'])]
class EcoCheckpointReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcoRunner::class, inversedBy: 'checkpointReports')]
    #[ORM\JoinColumn(name: 'runner_id', nullable: false, onDelete: 'CASCADE')]
    private EcoRunner $runner;

    #[ORM\ManyToOne(targetEntity: EcoCheckpoint::class)]
    #[ORM\JoinColumn(name: 'checkpoint_id', nullable: false, onDelete: 'CASCADE')]
    private EcoCheckpoint $checkpoint;

    // When the runner gave up on it, as the phone says (bounded like a scan's time).
    #[ORM\Column(name: 'reported_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $reportedAt;

    // Where they were when they reported it: a report made 400 m from the flag is not the same
    // statement as one made beside its stake.
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $latitude;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $longitude;

    public function __construct(EcoRunner $runner, EcoCheckpoint $checkpoint, \DateTimeImmutable $reportedAt, ?float $latitude, ?float $longitude)
    {
        $this->runner = $runner;
        $this->checkpoint = $checkpoint;
        $this->reportedAt = $reportedAt;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRunner(): EcoRunner
    {
        return $this->runner;
    }

    public function getCheckpoint(): EcoCheckpoint
    {
        return $this->checkpoint;
    }

    public function getReportedAt(): \DateTimeImmutable
    {
        return $this->reportedAt;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }
}
