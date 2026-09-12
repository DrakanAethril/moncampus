<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

/**
 * One offer could not be read. Carries the reason and, when it helps, the field it is about - the
 * API echoes both, the import screen prints them line by line.
 */
class OfferRejectedException extends \RuntimeException
{
    public function __construct(
        public readonly JobboardRejection $reason,
        public readonly ?string $field = null,
    ) {
        parent::__construct($reason->value.(null === $field ? '' : ' ('.$field.')'));
    }
}
