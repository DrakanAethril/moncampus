<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceConstraint;
use App\Enum\CrmPieceKind;

/** A piece as the settings' form describes it. */
final readonly class CrmPieceInput
{
    /**
     * @param list<CrmModality> $modalities
     * @param list<CrmPath>     $paths
     * @param list<string>      $choices
     */
    public function __construct(
        public string $label,
        public string $shortLabel,
        public CrmPhase $phase,
        public CrmPieceKind $kind,
        public array $modalities,
        public array $paths,
        public bool $foreignOnly,
        public CrmPieceConstraint $defaultConstraint,
        public bool $futureDateAllowed,
        public array $choices = [],
        public bool $lastValueCompletes = false,
        public bool $asksPaymentMode = false,
    ) {
    }
}
