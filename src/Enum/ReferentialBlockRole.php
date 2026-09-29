<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a block of a référentiel is **for** at the examination (design/validated/portfolio.md §7).
 *
 * France compétences publishes blocks, not examinations: which block the E5 synthesis table is
 * drawn from and which one the E6 fiches cover is read in the circulaire, and the administrator
 * confirms it when the référentiel is created. Nothing guesses it at run time.
 */
enum ReferentialBlockRole: string
{
    /** The block the E5 synthesis table is drawn from (BTS SIO: bloc 1, common to both options). */
    case Synthesis = 'synthesis';

    /** The block an E6 fiche covers (BTS SIO: bloc 2 of the student's option). */
    case Showcase = 'showcase';

    /** Published by the RNCP, followed by no portfolio screen (BTS SIO: the cybersecurity blocks). */
    case None = 'none';

    public function labelKey(): string
    {
        return match ($this) {
            self::Synthesis => 'referentialBlockRoleSynthesisLabel',
            self::Showcase => 'referentialBlockRoleShowcaseLabel',
            self::None => 'referentialBlockRoleNoneLabel',
        };
    }
}
