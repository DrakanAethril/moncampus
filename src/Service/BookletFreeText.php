<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A free text laid out for the Livret de l'alternant - see App\Service\BookletFreeTextLayout.
 *
 * @phpstan-type BookletSection array{number: int, label: string, anchor: string}
 */
final readonly class BookletFreeText
{
    /**
     * @param string               $html     the text, its headings restyled, numbered and anchored
     * @param list<BookletSection> $sections one per numbered heading, in order: what the sommaire lists
     */
    public function __construct(
        public string $html,
        public array $sections,
    ) {
    }
}
