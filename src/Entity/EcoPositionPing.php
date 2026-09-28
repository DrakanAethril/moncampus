<?php

declare(strict_types=1);

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
// What app:eco:read-terrain looks for on every pass: the fixes nobody has asked the IGN about yet.
#[ORM\Index(name: 'eco_position_ping_terrain_idx', columns: ['terrain_resolved', 'runner_id'])]
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

    // What the IGN says about the ground under this fix, written after the race by
    // app:eco:read-terrain (App\Service\Eco\EcoPingTerrainResolver): the altitude of the terrain
    // model - the elevation gain built on it no longer depends on the phone's barometer or GPS -
    // whether the fix sits on a path of the BD TOPO, and whether it sits in a wood.
    #[ORM\Column(name: 'ground_altitude', type: Types::FLOAT, nullable: true)]
    private ?float $groundAltitude = null;

    #[ORM\Column(name: 'on_path', nullable: true)]
    private ?bool $onPath = null;

    #[ORM\Column(name: 'in_forest', nullable: true)]
    private ?bool $inForest = null;

    // True once the IGN has been asked for this fix, whatever it answered: a fix outside its
    // coverage keeps its three nulls and is never asked about again.
    #[ORM\Column(name: 'terrain_resolved', options: ['default' => false])]
    private bool $terrainResolved = false;

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

    public function getGroundAltitude(): ?float
    {
        return $this->groundAltitude;
    }

    public function isOnPath(): ?bool
    {
        return $this->onPath;
    }

    public function isInForest(): ?bool
    {
        return $this->inForest;
    }

    public function isTerrainResolved(): bool
    {
        return $this->terrainResolved;
    }

    public function resolveTerrain(?float $groundAltitude, ?bool $onPath, ?bool $inForest): static
    {
        $this->groundAltitude = $groundAltitude;
        $this->onPath = $onPath;
        $this->inForest = $inForest;
        $this->terrainResolved = true;

        return $this;
    }
}
