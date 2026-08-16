<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\PlatformActivity;
use App\Entity\UfaActivity;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Composes the sentence of a log row (App\Entity\UfaActivity /
 * App\Entity\PlatformActivity): the tables store a type and a snapshot of names, never the sentence
 * itself - see the entities' docblocks. This is where the two meet.
 *
 * The payload is copied into %key% placeholders: adding a datum to display amounts to putting it in
 * the payload on the recorder side and using it in the translation, without touching this file.
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

        // A safety net for rows older than the sentence: adding a placeholder to a translation leaves
        // the payloads already written without the matching key, and the translator then renders the
        // %token% as is. It is erased rather than displaying plumbing.
        return trim(preg_replace('/\s*%[a-zA-Z0-9_]+%/', '', $message));
    }
}
