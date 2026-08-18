<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

/**
 * A host that answers but is misdeclared. Carried as a translation key plus its placeholders rather
 * than as a finished sentence, because the same result object is rendered by a Twig template, a
 * flash message and a console command.
 */
final readonly class ProxmoxCheckWarning
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public string $messageKey,
        public array $parameters = [],
    ) {
    }
}
