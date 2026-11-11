<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmOrigin;
use App\Entity\Option;
use App\Entity\Program;
use App\Enum\CrmModality;
use App\Enum\CrmPath;

/**
 * Where a dossier is opened or corrected to: the formation, its option, the path, the origins and
 * the prior schooling. The modality is only read when a dossier is opened - afterwards it changes
 * through « Changer de modalité », which keeps its history.
 */
final readonly class CrmDossierInput
{
    /**
     * @param list<CrmOrigin> $origins
     */
    public function __construct(
        public Program $program,
        public ?Option $option,
        public CrmPath $path,
        public ?CrmModality $modality,
        public array $origins = [],
        public ?string $priorSchooling = null,
    ) {
    }
}
