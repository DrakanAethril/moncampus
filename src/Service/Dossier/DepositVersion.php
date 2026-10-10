<?php

declare(strict_types=1);

namespace App\Service\Dossier;

/**
 * One dépôt on a App\Service\Dossier\RequestedPiece - a version among others.
 *
 * The highest version is the one that counts, and its last review is what decides the status.
 */
interface DepositVersion
{
    public function getVersion(): int;

    public function lastReview(): ?DepositReview;
}
