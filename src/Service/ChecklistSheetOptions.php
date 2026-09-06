<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * What the « Liste pour pointage » modal asked for: the page orientation, and the columns to add
 * next to each name.
 *
 * The sheet is a blank the establishment fills in by hand - passeport, vaccins, acompte when a trip
 * is being prepared - so the columns are free text, named by whoever prints the list, and nothing is
 * stored: the modal builds a URL, the URL builds the document.
 *
 * That makes this the boundary, and it reads like every other one on this platform (App\Service\
 * QueryValue): a hand-edited or half-filled query must print a usable sheet, never a 400. Blank
 * names are dropped, the count and the length are capped to what a printed cell can actually hold.
 */
final readonly class ChecklistSheetOptions
{
    /** Past this the cells stop being wide enough to write in, landscape included. */
    public const int MAX_COLUMNS = 10;

    /** A column heading is a word or two - « Passeport », « Acompte versé » - not a sentence. */
    public const int MAX_COLUMN_LENGTH = 40;

    /** @param list<string> $columns */
    public function __construct(
        public bool $landscape = false,
        public array $columns = [],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            'landscape' === QueryValue::trimmed($request, 'orientation'),
            self::columns($request),
        );
    }

    /** @return list<string> */
    private static function columns(Request $request): array
    {
        $raw = QueryValue::all($request, 'columns');
        if ([] === $raw) {
            // `?columns=Passeport` rather than `?columns[]=Passeport`: one column, not none.
            $single = QueryValue::trimmed($request, 'columns');
            $raw = '' !== $single ? [$single] : [];
        }

        $columns = [];
        foreach ($raw as $entry) {
            if (!\is_string($entry)) {
                continue;
            }

            $name = trim($entry);
            if ('' === $name) {
                continue;
            }

            $columns[] = mb_substr($name, 0, self::MAX_COLUMN_LENGTH);

            if (self::MAX_COLUMNS === \count($columns)) {
                break;
            }
        }

        return $columns;
    }
}
