<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

/**
 * Reads the contract's two dates out of the free-text "Observations" column.
 *
 * That column is prose typed by hand over several years, not a date field: the same file carries
 * "01/09/2025 au 31/08/2026", "Apprentissage du 02/02/2026 au 27/08/2027", "18.03.26 au 28.08.27",
 * "24/09/25 au 28/08/2027" and a trailing newline. So rather than trying to match a grammar, this
 * pulls every day/month/year token out of the string and keeps the first two - the wording around
 * them is decoration, the order never varies (start then end).
 *
 * Nothing is repaired here: a string yielding fewer than two dates, or an impossible one
 * (31/02), comes back null and the analysis reports the line as a blocking error rather than
 * guessing. Chronology is checked by the caller (see ContractPeriod::isChronological()) because a
 * reversed pair is a different message to the operator than an unreadable one - the file has one
 * of each.
 */
class ContractDateParser
{
    // Day, month, year separated by / . or -, the year on 4 digits OR 2. Anchored on nothing: the
    // tokens are picked out of whatever surrounds them.
    //
    // The 4-digit branch MUST come first. PCRE alternation is first-match, not longest-match, so
    // `\d{2}|\d{4}` reads "2025" as "20" and every contract of this decade lands in 2020.
    private const string DATE_PATTERN = '/(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})/';

    // A 2-digit year in a contract file is this century - "26" is 2026, never 1926.
    private const int CENTURY = 2000;

    public function parse(string $raw): ?ContractPeriod
    {
        if (0 === preg_match_all(self::DATE_PATTERN, $raw, $matches, \PREG_SET_ORDER) || \count($matches) < 2) {
            return null;
        }

        $start = $this->toDate($matches[0]);
        $end = $this->toDate($matches[1]);

        if (null === $start || null === $end) {
            return null;
        }

        return new ContractPeriod($start, $end);
    }

    /** @param array<int, string> $match day, month, year as captured */
    private function toDate(array $match): ?\DateTimeImmutable
    {
        $day = (int) $match[1];
        $month = (int) $match[2];
        $year = (int) $match[3];

        if ($year < 100) {
            $year += self::CENTURY;
        }

        // checkdate() rather than a lenient DateTimeImmutable: "31/02/2026" would otherwise become
        // 3 March, silently moving a contract boundary the operator never typed.
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return (new \DateTimeImmutable())->setDate($year, $month, $day)->setTime(0, 0);
    }
}
