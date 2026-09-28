<?php

declare(strict_types=1);

namespace App\Service\Eco;

use App\Entity\EcoParcours;
use App\Entity\User;

/**
 * Saves the validation radius of a parcours' flags - the one edit of screen 1e, and of the teacher
 * app's parcours screen, which must read a sent value the same way.
 *
 * `$tolerances` is keyed by checkpoint id. A flag absent from it, or sent something that is not a
 * number, keeps its radius; a radius under 1 m is raised to 1 m rather than refused, since a flag
 * nobody can validate is never what was meant. Only the flags of this parcours are read: an id
 * belonging to another one is simply never looked up.
 */
final class EcoToleranceEditor
{
    /** @param array<array-key, mixed> $tolerances checkpoint id => metres */
    public function apply(EcoParcours $parcours, array $tolerances, User $editor): void
    {
        foreach ($parcours->getCheckpoints() as $checkpoint) {
            $tolerance = $tolerances[(string) $checkpoint->getId()] ?? null;
            if (is_numeric($tolerance)) {
                $checkpoint->setToleranceMeters(max(1, (int) $tolerance));
            }
        }

        $parcours->setLastUpdatedBy($editor);
        $parcours->setLastUpdatedDate(new \DateTimeImmutable());
    }
}
