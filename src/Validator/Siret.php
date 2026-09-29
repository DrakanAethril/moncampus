<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * A SIRET that can exist: fourteen digits once the spaces are gone, and a check digit that checks
 * (App\Service\Sirene\Siret). Blank passes - no screen requires a SIRET, a contract arrives with a
 * company name long before anyone knows its number.
 *
 * Carried by every field a SIRET is typed into, and refuses rather than corrects: a wrong digit
 * turned into another valid number would point at a stranger (design/validated/siret-entreprises.md, R11).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class Siret extends Constraint
{
    public string $lengthMessage = 'siretLengthMessage';
    public string $checksumMessage = 'siretChecksumMessage';
}
