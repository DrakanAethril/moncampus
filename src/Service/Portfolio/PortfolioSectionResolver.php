<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Enum\PortfolioSetting;

/**
 * Which part(s) of the synthesis table a réalisation belongs to (R4).
 *
 * The official table has three parts: « Réalisation en cours de formation » (1), « … en milieu
 * professionnel en cours de première année » (2) and « … de seconde année » (3). Nobody picks the
 * part: a réalisation in training is always part 1; a workplace one goes to the cursus year of each
 * school year its dates touch - so an internship straddling the summer between SIO 1 and SIO 2
 * shows in both parts, which is what the table means.
 *
 * Works on primitives: the school years are passed in as spans, so the rule is tested without a
 * database and cannot depend on how a formation was loaded.
 *
 * When the dates fall in no known school year (a summer internship, if the school years stop in
 * June), the nearest school year that started before the réalisation ended answers; failing that,
 * the first one. A workplace réalisation with no date yet, or a student with no formation known,
 * is filed as first year - the student sees where it lands and can correct the dates.
 */
final class PortfolioSectionResolver
{
    public const int SECTION_TRAINING = 1;
    public const int SECTION_WORKPLACE_YEAR_1 = 2;
    public const int SECTION_WORKPLACE_YEAR_2 = 3;

    /**
     * @param list<array{from: \DateTimeImmutable, until: \DateTimeImmutable, cursusYear: int}> $years
     *
     * @return non-empty-list<int> the parts, ascending
     */
    public function sections(PortfolioSetting $setting, ?\DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn, array $years): array
    {
        if (PortfolioSetting::Training === $setting) {
            return [self::SECTION_TRAINING];
        }

        $start = $startsOn ?? $endsOn;
        $end = $endsOn ?? $startsOn;

        if (null === $start || null === $end || [] === $years) {
            return [self::SECTION_WORKPLACE_YEAR_1];
        }

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        $sections = [];
        foreach ($years as $year) {
            if ($start <= $year['until'] && $end >= $year['from']) {
                $sections[] = self::sectionOf($year['cursusYear']);
            }
        }

        if ([] === $sections) {
            usort($years, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);
            $nearest = $years[0];
            foreach ($years as $year) {
                if ($year['from'] <= $end) {
                    $nearest = $year;
                }
            }
            $sections[] = self::sectionOf($nearest['cursusYear']);
        }

        $sections = array_values(array_unique($sections));
        sort($sections);

        return $sections;
    }

    private static function sectionOf(int $cursusYear): int
    {
        return $cursusYear >= 2 ? self::SECTION_WORKPLACE_YEAR_2 : self::SECTION_WORKPLACE_YEAR_1;
    }
}
