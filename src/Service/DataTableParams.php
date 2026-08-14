<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\QueryValue;
use Symfony\Component\HttpFoundation\Request;

/**
 * The paging and search parameters a DataTables endpoint reads off the request.
 *
 * The same eight lines lived in three traits at once - SettingsTabTrait, ProgramSettingsTabTrait and
 * ProgramInternshipTrait - which is how the `search[value]` read came to be untyped in all three.
 * They now delegate here and keep handing their callers the same tuple, so none of the 28 call
 * sites had to change.
 *
 * The clamping is the reason this is worth a test rather than a helper: the page length comes
 * straight off the wire, and nothing else stops a request asking for the whole table in one go.
 */
final class DataTableParams
{
    private const int DEFAULT_LENGTH = 10;
    private const int MAX_LENGTH = 50;

    private function __construct(
        public readonly int $draw,
        public readonly int $start,
        public readonly int $length,
        public readonly string $search,
        public readonly bool $includeInactive,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $length = QueryValue::int($request, 'length', self::DEFAULT_LENGTH);

        // DataTables sends -1 for "show all", which this app does not offer.
        $length = $length > 0 ? min($length, self::MAX_LENGTH) : self::DEFAULT_LENGTH;

        // `search` arrives as a nested array (search[value]); anything else is not a search term.
        $search = QueryValue::all($request, 'search')['value'] ?? null;

        return new self(
            QueryValue::int($request, 'draw', 1),
            max(0, QueryValue::int($request, 'start', 0)),
            $length,
            \is_scalar($search) ? trim((string) $search) : '',
            $request->query->getBoolean('includeInactive'),
        );
    }

    /**
     * The shape the traits have always returned, so their callers keep destructuring it.
     *
     * @return array{0: int, 1: int, 2: int, 3: string, 4: bool}
     */
    public function toList(): array
    {
        return [$this->draw, $this->start, $this->length, $this->search, $this->includeInactive];
    }
}
