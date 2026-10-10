<?php

declare(strict_types=1);

namespace App\Service\Dossier;

/**
 * A piece somebody is asked to hand in - what App\Service\Dossier\DossierStatusResolver reads of it.
 *
 * Two families implement it: the documents of a dossier documentaire (App\Entity\DossierDocument)
 * and the pieces of a dossier intervenant (App\Entity\TeacherDossierDocument). They keep their own
 * tables and their own access rules; what they share is this - the four questions a status is
 * derived from - so the rule itself is written once.
 */
interface RequestedPiece
{
    /** Before this, the piece does not exist for whoever it is asked of. */
    public function isVisibleOn(\DateTimeImmutable $day): bool;

    public function isOverdueOn(\DateTimeImmutable $day): bool;

    /** May something still be handed in once the date limite has passed? */
    public function isLateAllowed(): bool;

    /** Does a dépôt open a conversation with a validateur, or end the matter? */
    public function needsValidation(): bool;
}
