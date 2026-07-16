<?php

namespace App\Service;

use App\Entity\Period;
use App\Entity\PeriodType;
use App\Entity\SchoolYear;

/**
 * Builds the Livret Alternant's "Calendrier d'alternance" page: a 12-month day grid starting
 * from the Program's SchoolYear, one cell per day, colored by whichever Period (if any) covers
 * that day - replaces the old cover/calendar image-upload slot, see InternshipBookletBuilder.
 *
 * Ported from the design reference's buildMois() (design/design_livret_alternant), with two
 * deliberate deviations: the month range is derived from the real SchoolYear instead of a
 * hardcoded 2024-2025 span, and Period::$endDate is treated as inclusive (date <= end) rather
 * than the reference's exclusive (date < end), matching how Period is treated everywhere else
 * in this app.
 */
class InternshipCalendarBuilder
{
    private const array MONTH_NAMES = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
        7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    /**
     * @param list<Period> $periods
     *
     * @return list<array{titre: string, cases: list<array{num: string, bg: string, fg: string}>}>
     */
    public function build(SchoolYear $schoolYear, array $periods): array
    {
        $months = [];
        $cursor = $schoolYear->getStartDate()->modify('first day of this month');

        for ($i = 0; $i < 12; ++$i) {
            $year = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');
            $daysInMonth = (int) $cursor->format('t');
            $firstDow = ((int) $cursor->format('N')) - 1; // Monday = 0 .. Sunday = 6

            $cases = [];
            for ($b = 0; $b < $firstDow; ++$b) {
                $cases[] = ['num' => '', 'bg' => '#ffffff', 'fg' => '#1b2430'];
            }

            for ($d = 1; $d <= $daysInMonth; ++$d) {
                $date = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $d));
                $dow = ($firstDow + $d - 1) % 7;
                $period = $this->findPeriodForDate($periods, $date);

                $bg = '#ffffff';
                if ($dow >= 5) {
                    $bg = '#e9ecef';
                } elseif (null !== $period) {
                    $bg = $period->getType()?->getColor() ?? '#ffffff';
                }

                $cases[] = ['num' => (string) $d, 'bg' => $bg, 'fg' => $this->isPublicHoliday($date) ? '#c0392b' : '#1b2430'];
            }

            $months[] = ['titre' => self::MONTH_NAMES[$month].' '.$year, 'cases' => $cases];
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    // One entry per distinct PeriodType among $periods (first-seen order), for the legend shown
    // alongside the calendar grid - shared by the Livret Alternant booklet and the standalone
    // alternance calendar PDF (App\Controller\ProgramController::alternanceCalendarPdf()).
    /**
     * @param list<Period> $periods
     *
     * @return list<array{color: string, label: string}>
     */
    public function buildLegend(array $periods): array
    {
        $seenTypeIds = [];
        $legend = [];

        foreach ($periods as $period) {
            $type = $period->getType();

            if (!$type instanceof PeriodType || isset($seenTypeIds[$type->getId()])) {
                continue;
            }

            $seenTypeIds[$type->getId()] = true;
            $legend[] = ['color' => $type->getColor(), 'label' => $type->getName()];
        }

        return $legend;
    }

    /** @param list<Period> $periods */
    private function findPeriodForDate(array $periods, \DateTimeImmutable $date): ?Period
    {
        foreach ($periods as $period) {
            if ($date >= $period->getStartDate() && $date <= $period->getEndDate()) {
                return $period;
            }
        }

        return null;
    }

    // French public holidays, computed rather than hardcoded (unlike the design reference's
    // fixed 2024-2025 date list) so the calendar stays correct for any school year.
    private function isPublicHoliday(\DateTimeImmutable $date): bool
    {
        $year = (int) $date->format('Y');
        $ymd = $date->format('Y-m-d');

        $fixed = [
            sprintf('%d-01-01', $year),
            sprintf('%d-05-01', $year),
            sprintf('%d-05-08', $year),
            sprintf('%d-07-14', $year),
            sprintf('%d-08-15', $year),
            sprintf('%d-11-01', $year),
            sprintf('%d-11-11', $year),
            sprintf('%d-12-25', $year),
        ];

        if (\in_array($ymd, $fixed, true)) {
            return true;
        }

        $easter = $this->easterSunday($year);
        $movable = [
            $easter->modify('+1 day')->format('Y-m-d'),  // Lundi de Pâques
            $easter->modify('+39 days')->format('Y-m-d'), // Ascension
            $easter->modify('+50 days')->format('Y-m-d'), // Lundi de Pentecôte
        ];

        return \in_array($ymd, $movable, true);
    }

    // Meeus/Jones/Butcher algorithm (Gregorian calendar) - avoids depending on ext-calendar's
    // easter_date(), which isn't guaranteed to be compiled in.
    private function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
