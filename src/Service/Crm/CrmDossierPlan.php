<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmPiece;
use App\Enum\CrmSituation;

/**
 * A dossier described by the connector, read and checked whole before anything is written
 * (App\Service\Crm\CrmDossierImport::plan()): the candidate, where it goes, its situation, the
 * pieces entered and the notes of its journal.
 */
final readonly class CrmDossierPlan
{
    /**
     * @param list<array{piece: CrmPiece, value: mixed, date: ?\DateTimeImmutable, dateUnknown: bool, paymentMode: ?string}> $pieces
     * @param list<array{date: ?\DateTimeImmutable, text: string}>                                                             $notes
     * @param list<string>                                                                                                     $notices what the journal is told besides (a default modality taken)
     */
    public function __construct(
        public CrmCandidateInput $candidate,
        public CrmDossierInput $placement,
        public CrmSituation $situation,
        public ?string $situationReason,
        public bool $refundDue,
        public bool $sfp,
        public array $pieces,
        public array $notes,
        public array $notices,
    ) {
    }
}
