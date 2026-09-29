<?php

declare(strict_types=1);

namespace App\Service\Sirene;

/**
 * The Recherche d'entreprises API did not answer, answered with an error (a 429 included), or
 * answered something RechercheEntreprisesClient does not recognise. Every caller degrades on it:
 * the screen says « Service de l'État indisponible », and typing a SIRET by hand, « Plus tard » and
 * « Pas de SIRET trouvable » all keep working (design/validated/siret-entreprises.md, R10).
 */
final class SireneUnavailableException extends \RuntimeException
{
}
