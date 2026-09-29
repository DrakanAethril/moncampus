<?php

declare(strict_types=1);

namespace App\Service\Sirene;

use App\Service\AlternanceImport\EnterpriseAddress;

/**
 * Where an employer's stored address says it is, as the SIRET search needs it: a postcode to
 * narrow on, and the street lines to compare with what SIRENE records.
 *
 * The address is free text - a postal block of several lines from the contract import
 * (« 16 RUE BERNARD LATHIERE » / « 87000-LIMOGES »), or one line typed on a screen
 * (« 1 rue de la Formation, 87000 Limoges ») - so a line is also cut at a comma that a postcode
 * follows, and the last segment that reads as a postcode line is the postcode line. Only there:
 * the comma of « 4, rue Legouvé » belongs to the street, and cutting it would part the number
 * from its street.
 *
 * A CEDEX postcode is a sorting office's, not the establishment's: the search then narrows on the
 * département only, and « same place » means the same département.
 */
final readonly class EmployerLocation
{
    /**
     * @param list<string> $streetLines
     */
    private function __construct(
        public ?string $postalCode,
        public bool $cedex,
        public array $streetLines,
    ) {
    }

    public static function read(?string $address): self
    {
        $segments = [];
        foreach (preg_split('/\R/u', $address ?? '') ?: [] as $line) {
            foreach (preg_split('/,\s*(?=\d{5}\b)/u', $line) ?: [] as $segment) {
                $segment = trim($segment);
                if ('' !== $segment) {
                    $segments[] = $segment;
                }
            }
        }

        $postalCode = null;
        $cedex = false;
        $postalIndex = null;
        foreach ($segments as $index => $segment) {
            if (1 === preg_match(EnterpriseAddress::POSTAL_LINE_PATTERN, $segment, $matches)) {
                $postalCode = substr($segment, 0, 5);
                $cedex = 1 === preg_match('/\bCEDEX\b/iu', $matches[1]);
                $postalIndex = $index;
            }
        }

        $streetLines = [];
        foreach ($segments as $index => $segment) {
            if ($index !== $postalIndex) {
                $streetLines[] = $segment;
            }
        }

        return new self($postalCode, $cedex, $streetLines);
    }

    /**
     * The département the postcode belongs to, as the API takes it - null where two digits do not
     * say it (Corsica shares « 20 » between 2A and 2B) or there is no postcode.
     */
    public function department(): ?string
    {
        if (null === $this->postalCode) {
            return null;
        }

        return match (true) {
            str_starts_with($this->postalCode, '97'), str_starts_with($this->postalCode, '98') => substr($this->postalCode, 0, 3),
            str_starts_with($this->postalCode, '20') => null,
            default => substr($this->postalCode, 0, 2),
        };
    }

    /** Whether an establishment's postcode is this employer's place. */
    public function isSamePlace(?string $postalCode): bool
    {
        if (null === $this->postalCode || null === $postalCode) {
            return false;
        }

        if ($this->cedex) {
            return null !== $this->department() && str_starts_with($postalCode, $this->department());
        }

        return $postalCode === $this->postalCode;
    }
}
