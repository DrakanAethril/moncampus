<?php

declare(strict_types=1);

namespace App\Service\Crm;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a CRM write refuses, with every reason at once: a dialog shows them under its form, the
 * connector returns them to Claude, the import attaches them to the line they come from.
 */
final class CrmRefusal extends \DomainException
{
    /** @param list<TranslatableMessage> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', array_map(static fn (TranslatableMessage $m): string => $m->getMessage(), $reasons)));
    }

    /** @param array<string, string|int> $parameters */
    public static function because(string $key, array $parameters = []): self
    {
        return new self([new TranslatableMessage($key, $parameters)]);
    }

    /** @return list<string> */
    public function texts(TranslatorInterface $translator, ?string $locale = null): array
    {
        return array_map(static fn (TranslatableMessage $m): string => $m->trans($translator, $locale), $this->reasons);
    }
}
