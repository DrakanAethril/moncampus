<?php

declare(strict_types=1);

namespace App\Service\Sirene;

/**
 * One company of a « Trouver une entreprise » page, as the register describes it, with **only its
 * open establishments that matched the search** - a closed one cannot take a trainee, and the
 * department filter is applied to establishments, not to the company (CGI France, head office in
 * the 92, comes back for the 87 with its Limoges sites).
 */
final readonly class RegistryCompany
{
    /**
     * @param list<EstablishmentCandidate> $establishments open, matching, never empty
     */
    public function __construct(
        public string $siren,
        public string $fullName,
        /** The company's main NAF code - the one the activity filter reads. */
        public ?string $activityCode,
        public ?string $employeeBracket,
        /** PME, ETI, GE, or null. */
        public ?string $category,
        public ?EstablishmentCandidate $headOffice,
        public array $establishments,
        public bool $individual,
        /** INSEE's « catégorie juridique », four digits: 1000 a sole trader, 92xx an association, 7xxx public law. */
        public ?string $legalForm = null,
    ) {
    }
}
