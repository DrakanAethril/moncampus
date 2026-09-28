<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EcoCheckpointType;
use App\Repository\EcoCheckpointRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One balise of an EcoParcours - see reference/e-CO.dc.html screen 1e. Located exclusively from
 * the mobile app (App\Controller\Api\EcoCheckpointLocationController, not built in this phase):
 * the teacher walks to the spot, scans the QR code (or re-scans to update), which POSTs the GPS
 * fix that fills $latitude/$longitude/$locatedAt here.
 */
#[ORM\Entity(repositoryClass: EcoCheckpointRepository::class)]
#[ORM\Table(name: 'eco_checkpoint')]
#[ORM\UniqueConstraint(name: 'eco_checkpoint_short_code_unique', columns: ['short_code'])]
class EcoCheckpoint
{
    // Applied to a checkpoint at creation (App\Service\EcoParcoursFactory) as its starting
    // tolerance value - not a fallback read at scan-validation time, since every checkpoint always
    // has its own stored, editable value (screen 1e's "surchargeable par balise" input).
    public const int DEFAULT_TOLERANCE_METERS = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcoParcours::class, inversedBy: 'checkpoints')]
    #[ORM\JoinColumn(name: 'parcours_id', nullable: false)]
    private ?EcoParcours $parcours = null;

    #[ORM\Column(length: 20, enumType: EcoCheckpointType::class)]
    private EcoCheckpointType $type = EcoCheckpointType::Checkpoint;

    // Ordering/sequence index: 0 for Start, 1..N for the regular checkpoints in course order,
    // N+1 for Finish. Also what "Ordre imposé" scan validation checks a runner's next scan against.
    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    // Free-text parenthetical shown next to the name (1e: "Balise 3 (passerelle)") - not
    // structured data, purely a note for whoever prints/reads the checkpoint list.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    // Secours if the QR is unreadable (1e/4b/3e) - entered manually, journalised the same as a
    // real scan (see EcoCheckpointScan::$method).
    #[ORM\Column(name: 'short_code', length: 20)]
    private ?string $shortCode = null;

    #[ORM\Column(name: 'tolerance_meters')]
    #[Assert\Positive]
    private int $toleranceMeters = self::DEFAULT_TOLERANCE_METERS;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(name: 'located_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $locatedAt = null;

    // What the IGN says about the ground under the flag, read once per position
    // (App\Service\Eco\EcoCheckpointTerrainReader): the altitude of the terrain model, and the height
    // of the vegetation around it - under a tall canopy a phone's fix wanders further, which is
    // what the tolerance advice is built on. All three are cleared by locate(): a moved flag
    // stands on other ground.
    #[ORM\Column(name: 'ground_altitude', type: Types::FLOAT, nullable: true)]
    private ?float $groundAltitude = null;

    #[ORM\Column(name: 'canopy_height', type: Types::FLOAT, nullable: true)]
    private ?float $canopyHeight = null;

    // Set once the IGN has answered for the current position, even when it had no value to give
    // (a spot outside its coverage): null means "not asked yet", not "nothing there".
    #[ORM\Column(name: 'terrain_read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $terrainReadAt = null;

    public function __construct(EcoParcours $parcours)
    {
        $this->parcours = $parcours;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParcours(): ?EcoParcours
    {
        return $this->parcours;
    }

    public function getType(): EcoCheckpointType
    {
        return $this->type;
    }

    public function setType(EcoCheckpointType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getShortCode(): ?string
    {
        return $this->shortCode;
    }

    public function setShortCode(?string $shortCode): static
    {
        $this->shortCode = $shortCode;

        return $this;
    }

    public function getToleranceMeters(): int
    {
        return $this->toleranceMeters;
    }

    public function setToleranceMeters(int $toleranceMeters): static
    {
        $this->toleranceMeters = $toleranceMeters;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function getLocatedAt(): ?\DateTimeImmutable
    {
        return $this->locatedAt;
    }

    public function isLocated(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }

    // The only way $latitude/$longitude/$locatedAt ever change - always both coordinates and the
    // timestamp together, there's no scenario that updates just one of the three (initial scan and
    // a later re-scan both go through here identically).
    public function locate(float $latitude, float $longitude, \DateTimeImmutable $locatedAt): static
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->locatedAt = $locatedAt;
        $this->groundAltitude = null;
        $this->canopyHeight = null;
        $this->terrainReadAt = null;

        return $this;
    }

    public function getGroundAltitude(): ?float
    {
        return $this->groundAltitude;
    }

    public function getCanopyHeight(): ?float
    {
        return $this->canopyHeight;
    }

    public function getTerrainReadAt(): ?\DateTimeImmutable
    {
        return $this->terrainReadAt;
    }

    public function recordTerrain(?float $groundAltitude, ?float $canopyHeight, \DateTimeImmutable $readAt): static
    {
        $this->groundAltitude = $groundAltitude;
        $this->canopyHeight = $canopyHeight;
        $this->terrainReadAt = $readAt;

        return $this;
    }
}
