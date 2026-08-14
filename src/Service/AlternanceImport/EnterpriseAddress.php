<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

/**
 * Reads the file's "Entreprise : Adresse complète" cell into the two fields Enterprise holds.
 *
 * The cell is a postal block on several lines whose FIRST line repeats the company name:
 *
 *     EVA TEAM
 *     37, route de Poulenat
 *     87220-BOISSEUIL
 *
 * Kept as-is, every employer's address would open with its own name for no reason, and
 * Enterprise::$city (filled on the "nouvelle entreprise" screen, and the only field the employer
 * lists show beside the name) would stay empty although the town is right there. So the repeated
 * line is dropped when it *is* the name - never otherwise, since a company whose street line
 * genuinely starts with its own name still needs it - and the town is read off the last line.
 *
 * The postcode is left in the address rather than split out: Enterprise has no field for it, and
 * an address without its postcode is worse than one with.
 */
class EnterpriseAddress
{
    /** "87000-LIMOGES", "33090-BORDEAUX CEDEX", and the "87000 LIMOGES" spelling for good measure. */
    private const string POSTAL_LINE_PATTERN = '/^\d{5}\s*[-\s]\s*(.+)$/u';

    /** The address to store: the block minus the line that merely repeats the company name. */
    public function postalAddress(string $raw, string $enterpriseName): ?string
    {
        $lines = $this->lines($raw);

        if ([] !== $lines && $this->sameText($lines[0], $enterpriseName)) {
            array_shift($lines);
        }

        return [] !== $lines ? implode("\n", $lines) : null;
    }

    /** The town, read off the postcode line - null when the block has none. */
    public function city(string $raw): ?string
    {
        foreach (array_reverse($this->lines($raw)) as $line) {
            if (1 === preg_match(self::POSTAL_LINE_PATTERN, $line, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function lines(string $raw): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function sameText(string $left, string $right): bool
    {
        $normalize = static fn (string $value): string => trim(preg_replace('/\s+/', ' ', mb_strtolower($value)) ?? '');

        return $normalize($left) === $normalize($right);
    }
}
