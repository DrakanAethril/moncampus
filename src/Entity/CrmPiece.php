<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceConstraint;
use App\Enum\CrmPieceKind;
use App\Repository\CrmPieceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing a dossier asks for - a box to tick, a choice, an amount, a period, a list of values -
 * in one of the three stages, for some modalities and some paths. A piece is asked of every
 * formation with its own default (required, optional or hidden); each formation may say otherwise
 * (App\Entity\CrmProgram::$pieceConstraints).
 *
 * The `code` is what the connector and the import name a piece by, and it never changes. Three
 * pieces the CRM reads itself keep their kind for ever (systemKinds()): the deposit (its amount,
 * its payment mode, its refund), the NPEC (the formation's amount) and the visa (« Visa en
 * attente »). Every other piece is edited whole until it has a history in a dossier; then its kind
 * is frozen and its values already chosen stay in its list.
 */
#[ORM\Entity(repositoryClass: CrmPieceRepository::class)]
#[ORM\Table(name: 'crm_piece')]
#[ORM\UniqueConstraint(name: 'uniq_crm_piece_code', columns: ['code'])]
class CrmPiece
{
    public const string DEPOSIT = 'deposit';
    public const string NPEC = 'npec';
    public const string VISA = 'visa';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $label;

    /** The column head on a formation's board. */
    #[ORM\Column(length: 30)]
    private string $shortLabel;

    #[ORM\Column(length: 20, enumType: CrmPhase::class)]
    private CrmPhase $phase;

    #[ORM\Column(length: 20, enumType: CrmPieceKind::class)]
    private CrmPieceKind $kind;

    /** @var list<string> CrmModality values */
    #[ORM\Column(type: Types::JSON)]
    private array $modalities;

    /** @var list<string> CrmPath values */
    #[ORM\Column(type: Types::JSON)]
    private array $paths;

    #[ORM\Column]
    private bool $foreignOnly = false;

    /** What every formation makes of the piece unless it says otherwise. */
    #[ORM\Column(length: 20, enumType: CrmPieceConstraint::class)]
    private CrmPieceConstraint $defaultConstraint = CrmPieceConstraint::Required;

    /** Whether a date to come is accepted - a convocation, an arrival. */
    #[ORM\Column]
    private bool $futureDateAllowed = false;

    /** @var list<string> the values of a choice or a list, in order */
    #[ORM\Column(type: Types::JSON)]
    private array $choices = [];

    /** A list done only on its last value (a status: « Convention signée »). */
    #[ORM\Column]
    private bool $lastValueCompletes = false;

    /** An amount that also asks how it was paid. */
    #[ORM\Column]
    private bool $asksPaymentMode = false;

    #[ORM\Column]
    private int $position;

    /**
     * @param list<CrmModality> $modalities
     * @param list<CrmPath>     $paths
     */
    public function __construct(string $code, string $label, string $shortLabel, CrmPhase $phase, CrmPieceKind $kind, array $modalities, array $paths, int $position)
    {
        $this->code = $code;
        $this->label = $label;
        $this->shortLabel = $shortLabel;
        $this->phase = $phase;
        $this->kind = $kind;
        $this->setModalities($modalities);
        $this->setPaths($paths);
        $this->position = $position;
    }

    /** @return list<string> */
    public static function systemCodes(): array
    {
        return [self::DEPOSIT, self::NPEC, self::VISA];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function isSystem(): bool
    {
        return \in_array($this->code, self::systemCodes(), true);
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getShortLabel(): string
    {
        return $this->shortLabel;
    }

    public function setShortLabel(string $shortLabel): void
    {
        $this->shortLabel = $shortLabel;
    }

    public function getPhase(): CrmPhase
    {
        return $this->phase;
    }

    public function setPhase(CrmPhase $phase): void
    {
        $this->phase = $phase;
    }

    public function getKind(): CrmPieceKind
    {
        return $this->kind;
    }

    public function setKind(CrmPieceKind $kind): void
    {
        $this->kind = $kind;
    }

    /** @return list<CrmModality> */
    public function getModalities(): array
    {
        return array_values(array_filter(array_map(CrmModality::tryFrom(...), $this->modalities)));
    }

    public function isFor(CrmModality $modality): bool
    {
        return \in_array($modality->value, $this->modalities, true);
    }

    /** @param list<CrmModality> $modalities */
    public function setModalities(array $modalities): void
    {
        $values = array_map(static fn (CrmModality $m): string => $m->value, $modalities);
        // Kept in the enum's order, whatever order they were ticked in.
        // « Non renseignée » is never asked anything: it is dropped here whoever sends it.
        $this->modalities = array_values(array_filter(array_map(static fn (CrmModality $m): string => $m->value, CrmModality::pieceModalities()), static fn (string $v): bool => \in_array($v, $values, true)));
    }

    /** @return list<CrmPath> */
    public function getPaths(): array
    {
        return array_values(array_filter(array_map(CrmPath::tryFrom(...), $this->paths)));
    }

    public function isOnPath(CrmPath $path): bool
    {
        return \in_array($path->value, $this->paths, true);
    }

    /** @param list<CrmPath> $paths */
    public function setPaths(array $paths): void
    {
        $values = array_map(static fn (CrmPath $p): string => $p->value, $paths);
        $this->paths = array_values(array_filter(array_map(static fn (CrmPath $p): string => $p->value, CrmPath::cases()), static fn (string $v): bool => \in_array($v, $values, true)));
    }

    public function isForeignOnly(): bool
    {
        return $this->foreignOnly;
    }

    public function setForeignOnly(bool $foreignOnly): void
    {
        $this->foreignOnly = $foreignOnly;
    }

    public function defaultConstraint(): CrmPieceConstraint
    {
        return $this->defaultConstraint;
    }

    public function setDefaultConstraint(CrmPieceConstraint $defaultConstraint): void
    {
        $this->defaultConstraint = $defaultConstraint;
    }

    public function isFutureDateAllowed(): bool
    {
        return $this->futureDateAllowed;
    }

    public function setFutureDateAllowed(bool $futureDateAllowed): void
    {
        $this->futureDateAllowed = $futureDateAllowed;
    }

    /** @return list<string> */
    public function getChoices(): array
    {
        return $this->choices;
    }

    /** @param list<string> $choices */
    public function setChoices(array $choices): void
    {
        $this->choices = array_values(array_unique(array_values(array_filter(array_map('trim', $choices), static fn (string $c): bool => '' !== $c))));
    }

    public function lastChoice(): ?string
    {
        return [] === $this->choices ? null : $this->choices[\count($this->choices) - 1];
    }

    public function isLastValueCompletes(): bool
    {
        return $this->lastValueCompletes;
    }

    public function setLastValueCompletes(bool $lastValueCompletes): void
    {
        $this->lastValueCompletes = $lastValueCompletes;
    }

    public function isAsksPaymentMode(): bool
    {
        return $this->asksPaymentMode;
    }

    public function setAsksPaymentMode(bool $asksPaymentMode): void
    {
        $this->asksPaymentMode = $asksPaymentMode;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
