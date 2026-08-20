<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * The keys a machine is being created with, and who each of them belongs to.
 *
 * Two halves of one reading: `$material` is what cloud-init is given, `$descriptors` is what the
 * machine's installation log records. They are carried together so that what is written down can
 * never describe a different set from what was installed - which is the only thing that makes the
 * log worth reading when somebody cannot get in.
 */
final class AuthorizedKeySet
{
    /**
     * @param ?string          $material    the keys as cloud-init wants them, one per line, or null
     *                                      when there are none - which leaves the parameter out
     * @param list<string>     $descriptors one per installed key, in the same order
     */
    public function __construct(
        public readonly ?string $material,
        public readonly array $descriptors,
    ) {
    }
}
