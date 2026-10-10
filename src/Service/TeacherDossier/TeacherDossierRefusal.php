<?php

declare(strict_types=1);

namespace App\Service\TeacherDossier;

/**
 * A write on a dossier intervenant that the rules turn down. Its message is a **translation key**:
 * the controller hands it straight to the flash bag, so the refusal is worded in one place.
 */
final class TeacherDossierRefusal extends \DomainException
{
}
