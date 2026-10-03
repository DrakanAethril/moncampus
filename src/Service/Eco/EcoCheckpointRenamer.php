<?php

declare(strict_types=1);

namespace App\Service\Eco;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Entity\User;

/**
 * Renames a parcours' flags - the « Nom » column of screen 1e, saved with the tolerances. The name
 * is what the runner reads on the phone and what the printed page of each flag shows in large; the
 * flag's role (Départ, Arrivée, numbered balise) is its type and its position, never its name, so
 * any flag may be renamed, the Départ and the Arrivée included.
 *
 * `$names` is keyed by checkpoint id. A flag absent from it, or sent a blank name, keeps its own:
 * a flag with no name prints an empty page. Spaces are collapsed and the name is cut at
 * EcoCheckpoint::NAME_MAX_LENGTH, which is what still fits a printed page on two lines. Only the
 * flags of this parcours are read: an id belonging to another one is never looked up.
 */
final class EcoCheckpointRenamer
{
    /** @param array<array-key, mixed> $names checkpoint id => name */
    public function apply(EcoParcours $parcours, array $names, User $editor): void
    {
        $renamed = false;
        foreach ($parcours->getCheckpoints() as $checkpoint) {
            $name = $names[(string) $checkpoint->getId()] ?? null;
            if (!\is_string($name)) {
                continue;
            }

            $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, EcoCheckpoint::NAME_MAX_LENGTH);
            if ('' === $name || $name === $checkpoint->getName()) {
                continue;
            }

            $checkpoint->setName($name);
            $renamed = true;
        }

        if ($renamed) {
            $parcours->setLastUpdatedBy($editor);
            $parcours->setLastUpdatedDate(new \DateTimeImmutable());
        }
    }
}
