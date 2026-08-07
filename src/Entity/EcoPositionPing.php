<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// One GPS fix logged every ~5s while a runner races (from Start scan to Finish scan) - the raw
// material for the dashed polyline trace on reference/e-CO.dc.html screen 1i. Submitted in
// batches by the mobile app's offline queue (App\Controller\Api\EcoTelemetryController, not
// built in this phase), so $recordedAt is the phone's own clock at capture time, not server
// receipt time - it's what makes replaying a batch that arrived late still land in the right
// place on the trace.
#[ORM\Entity(repositoryClass: \App\Repository\EcoPositionPingRepository::class)]
#[ORM\Table(name: 'eco_position_ping')]
#[ORM\Index(name: 'eco_position_ping_runner_recorded_idx', columns: ['runner_id', 'recorded_at'])]
class EcoPositionPing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcoRunner::class)]
    #[ORM\JoinColumn(name: 'runner_id', nullable: false)]
    private ?EcoRunner $runner = null;

    #[ORM\Column(name: 'recorded_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $recordedAt = null;

    #[ORM\Column(type: Types::FLOAT)]
    private ?float $latitude = null;

    #[ORM\Column(type: Types::FLOAT)]
    private ?float $longitude = null;

    // Metres above sea level as the phone reported them, null when it had no altitude fix (and on
    // every ping logged before this column existed) - a GPS altitude is far noisier than its
    // latitude/longitude, which is why the elevation gain built from it is smoothed rather than
    // summed fix by fix (see EcoRunnerStatsCalculator).
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $altitude = null;

    public function __construct(EcoRunner $runner, \DateTimeImmutable $recordedAt, float $latitude, float $longitude, ?float $altitude = null)
    {
        $this->runner = $runner;
        $this->recordedAt = $recordedAt;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->altitude = $altitude;
    }

    public function getAltitude(): ?float
    {
        return $this->altitude;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRunner(): ?EcoRunner
    {
        return $this->runner;
    }

    public function getRecordedAt(): ?\DateTimeImmutable
    {
        return $this->recordedAt;
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
