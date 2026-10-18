<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeriousGameRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A published serious game (design/validated/serious-games.md §4) - here L'Observatoire.
 *
 * Games are **data, not cases of an enum**: the row is written from
 * `config/serious_games/{slug}/game.yaml` by `app:serious-games:sync`, and the per-role switches of
 * Paramètres › Fonctionnalités hang off it (SeriousGameRoleSetting). The game itself is a static
 * package under `public/serious-games/{slug}/`, played in an isolated frame.
 *
 * `$manifest` carries what the yaml says about the game's structure - its chapters and units, the
 * final test, the series size, the rewards - and is read by App\SeriousGame\SeriousGameManifest,
 * never by hand.
 *
 * `$answerKinds` is the administrator's setting of §1.3: the kinds of answer this game accepts
 * (`short` for L'Observatoire). A unit whose template asks for another kind is hidden.
 */
#[ORM\Entity(repositoryClass: SeriousGameRepository::class)]
#[ORM\Table(name: 'serious_game')]
#[ORM\UniqueConstraint(name: 'uniq_serious_game_slug', columns: ['slug'])]
class SeriousGame
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'slug', length: 64)]
    private string $slug;

    #[ORM\Column(name: 'title', length: 120)]
    private string $title;

    #[ORM\Column(name: 'pitch', length: 255)]
    private string $pitch = '';

    /** @var list<string> */
    #[ORM\Column(name: 'subjects', type: Types::JSON)]
    private array $subjects = [];

    /** @var list<string> */
    #[ORM\Column(name: 'levels', type: Types::JSON)]
    private array $levels = [];

    #[ORM\Column(name: 'unit_label', length: 60)]
    private string $unitLabel = '';

    #[ORM\Column(name: 'units_total')]
    private int $unitsTotal = 0;

    /** @var list<string> */
    #[ORM\Column(name: 'answer_kinds', type: Types::JSON)]
    private array $answerKinds = ['short'];

    #[ORM\Column(name: 'published')]
    private bool $published = false;

    #[ORM\Column(name: 'version', length: 20)]
    private string $version = '1.0.0';

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'manifest', type: Types::JSON)]
    private array $manifest = [];

    public function __construct(string $slug, string $title)
    {
        $this->slug = $slug;
        $this->title = $title;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getPitch(): string
    {
        return $this->pitch;
    }

    public function setPitch(string $pitch): static
    {
        $this->pitch = $pitch;

        return $this;
    }

    /** @return list<string> */
    public function getSubjects(): array
    {
        return $this->subjects;
    }

    /** @param array<array-key, string> $subjects */
    public function setSubjects(array $subjects): static
    {
        $this->subjects = array_values($subjects);

        return $this;
    }

    /** @return list<string> */
    public function getLevels(): array
    {
        return $this->levels;
    }

    /** @param array<array-key, string> $levels */
    public function setLevels(array $levels): static
    {
        $this->levels = array_values($levels);

        return $this;
    }

    public function getUnitLabel(): string
    {
        return $this->unitLabel;
    }

    public function setUnitLabel(string $unitLabel): static
    {
        $this->unitLabel = $unitLabel;

        return $this;
    }

    public function getUnitsTotal(): int
    {
        return $this->unitsTotal;
    }

    public function setUnitsTotal(int $unitsTotal): static
    {
        $this->unitsTotal = $unitsTotal;

        return $this;
    }

    /** @return list<string> */
    public function getAnswerKinds(): array
    {
        return $this->answerKinds;
    }

    /** @param array<array-key, string> $answerKinds */
    public function setAnswerKinds(array $answerKinds): static
    {
        $this->answerKinds = array_values(array_unique($answerKinds));

        return $this;
    }

    public function acceptsAnswerKind(string $kind): bool
    {
        return \in_array($kind, $this->answerKinds, true);
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): static
    {
        $this->published = $published;

        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    /** @param array<string, mixed> $manifest */
    public function setManifest(array $manifest): static
    {
        $this->manifest = $manifest;

        return $this;
    }
}
