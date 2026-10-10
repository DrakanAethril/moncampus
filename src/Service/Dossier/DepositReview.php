<?php

declare(strict_types=1);

namespace App\Service\Dossier;

/** What was answered to a dépôt: a validation, or a correction request. */
interface DepositReview
{
    public function isValidation(): bool;
}
