<?php

namespace App\Twig;

use App\Entity\PlatformActivity;
use App\Entity\UfaActivity;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Compose la phrase d'une ligne de journal (App\Entity\UfaActivity /
 * App\Entity\PlatformActivity) : les tables stockent un type et un instantané de noms, jamais la
 * phrase elle-même - voir le docblock des entités. C'est ici que les deux se rejoignent.
 *
 * Le payload est recopié en placeholders %clé% : ajouter une donnée à afficher revient à la
 * mettre dans le payload côté recorder et à s'en servir dans la traduction, sans toucher à ce
 * fichier.
 */
class ActivityExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('activity_message', $this->message(...)),
        ];
    }

    public function message(UfaActivity|PlatformActivity $activity): string
    {
        $parameters = [];
        foreach ($activity->getPayload() as $key => $value) {
            $parameters['%'.$key.'%'] = (string) $value;
        }

        $message = $this->translator->trans($activity->getType()->messageKey(), $parameters);

        // Filet pour les lignes plus anciennes que la phrase : ajouter un placeholder à une
        // traduction laisse les payloads déjà écrits sans la clé correspondante, et le traducteur
        // rend alors le %jeton% tel quel. On l'efface plutôt que d'afficher de la plomberie.
        return trim(preg_replace('/\s*%[a-zA-Z0-9_]+%/', '', $message));
    }
}
