<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CrmModality;
use App\Enum\CrmPieceConstraint;
use App\Repository\CrmProgramRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The CRM settings of one Formatool formation - a class of one school year, never a filière, so
 * that nothing is lost from one year to the next and a whole year's settings can be copied onto
 * another one.
 *
 * A formation the CRM never touched has no row: App\Service\Crm\CrmSettings answers the defaults
 * for it (not followed, initial training, no amount, every piece as the catalogue says).
 */
#[ORM\Entity(repositoryClass: CrmProgramRepository::class)]
#[ORM\Table(name: 'crm_program')]
#[ORM\UniqueConstraint(name: 'uniq_crm_program', columns: ['program_id'])]
class CrmProgram
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Program $program;

    /** Whether the formation is followed in the CRM that year; unticking it keeps its dossiers. */
    #[ORM\Column]
    private bool $followed = false;

    /** The modality a new dossier of the formation starts in. */
    #[ORM\Column(length: 20, enumType: CrmModality::class)]
    private CrmModality $defaultModality = CrmModality::Initial;

    /**
     * The ids of the formation's Formatool options the CRM follows apart: a dossier then names one
     * of them at pre-enrolment and the overview counts each on its own line. Empty, the formation
     * is followed as one and asks for no option.
     *
     * @var list<int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $trackedOptionIds = [];

    /** The deposit pre-filled when it is entered, in whole euros; null when there is none. */
    #[ORM\Column(nullable: true)]
    private ?int $depositAmount = null;

    #[ORM\Column(nullable: true)]
    private ?int $npecAmount = null;

    /**
     * An option's own amounts, by option id: `{"12": {"deposit": 250, "npec": null}}`. A null
     * amount, or an option absent, takes the formation's.
     *
     * @var array<array-key, array{deposit?: int|null, npec?: int|null}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $optionAmounts = [];

    /**
     * What the formation makes of a piece, by piece code, when it differs from the piece's own
     * default. An absent code follows the catalogue.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $pieceConstraints = [];

    public function __construct(Program $program)
    {
        $this->program = $program;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    public function isFollowed(): bool
    {
        return $this->followed;
    }

    public function setFollowed(bool $followed): void
    {
        $this->followed = $followed;
    }

    public function getDefaultModality(): CrmModality
    {
        return $this->defaultModality;
    }

    public function setDefaultModality(CrmModality $defaultModality): void
    {
        $this->defaultModality = $defaultModality;
    }

    /** @return list<int> */
    public function getTrackedOptionIds(): array
    {
        return $this->trackedOptionIds;
    }

    /** @param array<array-key, int|string|null> $trackedOptionIds */
    public function setTrackedOptionIds(array $trackedOptionIds): void
    {
        $ids = array_filter(array_map(intval(...), $trackedOptionIds), static fn (int $id): bool => $id > 0);
        $ids = array_values(array_unique($ids));
        sort($ids);
        $this->trackedOptionIds = $ids;
    }

    public function tracksOption(int $optionId): bool
    {
        return \in_array($optionId, $this->trackedOptionIds, true);
    }

    public function getDepositAmount(): ?int
    {
        return $this->depositAmount;
    }

    public function setDepositAmount(?int $depositAmount): void
    {
        $this->depositAmount = $depositAmount;
    }

    public function getNpecAmount(): ?int
    {
        return $this->npecAmount;
    }

    public function setNpecAmount(?int $npecAmount): void
    {
        $this->npecAmount = $npecAmount;
    }

    /** @return array<array-key, array{deposit?: int|null, npec?: int|null}> */
    public function getOptionAmounts(): array
    {
        return $this->optionAmounts;
    }

    /** @param array<array-key, array{deposit?: int|null, npec?: int|null}> $optionAmounts */
    public function setOptionAmounts(array $optionAmounts): void
    {
        $clean = [];
        foreach ($optionAmounts as $optionId => $amounts) {
            $deposit = $amounts['deposit'] ?? null;
            $npec = $amounts['npec'] ?? null;
            if (null !== $deposit || null !== $npec) {
                $clean[$optionId] = ['deposit' => $deposit, 'npec' => $npec];
            }
        }
        $this->optionAmounts = $clean;
    }

    public function optionAmount(int $optionId, string $kind): ?int
    {
        $amounts = $this->optionAmounts[$optionId] ?? [];

        return 'npec' === $kind ? ($amounts['npec'] ?? null) : ($amounts['deposit'] ?? null);
    }

    /** @return array<string, string> */
    public function getPieceConstraints(): array
    {
        return $this->pieceConstraints;
    }

    /** @param array<array-key, string> $pieceConstraints */
    public function setPieceConstraints(array $pieceConstraints): void
    {
        $clean = [];
        foreach ($pieceConstraints as $code => $constraint) {
            if (null !== CrmPieceConstraint::tryFrom($constraint)) {
                $clean[(string) $code] = $constraint;
            }
        }
        $this->pieceConstraints = $clean;
    }

    public function pieceConstraint(string $code): ?CrmPieceConstraint
    {
        $value = $this->pieceConstraints[$code] ?? null;

        return null === $value ? null : CrmPieceConstraint::tryFrom($value);
    }

    /** Sets a formation's own constraint for a piece; null goes back to the catalogue's default. */
    public function setPieceConstraint(string $code, ?CrmPieceConstraint $constraint): void
    {
        if (null === $constraint) {
            unset($this->pieceConstraints[$code]);

            return;
        }

        $this->pieceConstraints[$code] = $constraint->value;
    }
}
