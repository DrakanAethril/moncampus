<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The duplication does not fit in the recipient's library, so **nothing is written** - not the
 * séquence, not the folders, not the first three files that would have fitted
 * (design/validated/content-sharing-between-teachers.md, "Quota: measured first, all-or-nothing").
 *
 * The lesson is the class import's, « un bloquant refuse tout le fichier », and it applies verbatim.
 * A partial write is precisely what looks like a success.
 *
 * It carries its two figures because « il manque 34 Mo » is actionable where « quota dépassé » is
 * not.
 */
class ContentShareQuotaException extends \RuntimeException
{
    public function __construct(
        public readonly int $requiredBytes,
        public readonly int $remainingBytes,
    ) {
        parent::__construct(\sprintf('The duplication needs %d bytes and %d remain.', $requiredBytes, $remainingBytes));
    }

    public function shortfallBytes(): int
    {
        return max(0, $this->requiredBytes - $this->remainingBytes);
    }
}
