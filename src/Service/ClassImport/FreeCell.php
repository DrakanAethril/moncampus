<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

/**
 * One cell of a column that is neither `nom`, `prenom` nor `mail` - so, an option or a modality of
 * the destination class, written in clear.
 *
 * Both spellings of the header travel with the value because both are used to resolve it: the
 * folded one decides whether the column names an option or a modality outright, the raw one is
 * what the verification screen shows the operator.
 */
final readonly class FreeCell
{
    public function __construct(
        public string $header,
        public string $foldedHeader,
        public string $value,
    ) {
    }
}
